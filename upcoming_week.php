<?php
/**
 * Upcoming 7-Day Weather Dashboard
 *
 * A dashboard page for field-service / job-scheduling applications that combines:
 *   - 7-day animated weather forecast cards (Google Weather API)
 *   - Hourly forecast scrollbar for the selected day
 *   - Job cards for the selected day, with per-job weather-at-job-time badges
 *   - Full Google Calendar integration (view, create, edit, delete events)
 *
 * Requirements:
 *   PHP 8.1+, PDO MySQL, cURL
 *   Google Weather API   https://developers.google.com/maps/documentation/weather
 *   Google Calendar API  OAuth 2.0 (refresh-token flow, stored in `google_tokens` table for ours)
 *
 * Quick start:
 *   1. Fill in the $CONFIG values below (or load from environment variables).
 *   2. Set WEATHER_LAT / WEATHER_LON / WEATHER_CITY for your location.
 *   3. Run the Google OAuth flow once to populate the `google_tokens` table if you're not already.
 *   4. Adjust the SQL query to match your jobs/customers schema.
 */

// Configuration: replace with your own values or load from $_ENV / .env
$CONFIG = [
    'DB_HOST'              => $_ENV['DB_HOST']              ?? 'localhost',
    'DB_NAME'              => $_ENV['DB_NAME']              ?? 'your_database_name',
    'DB_USER'              => $_ENV['DB_USER']              ?? 'your_database_user',
    'DB_PASS'              => $_ENV['DB_PASS']              ?? 'your_database_password',
    'GOOGLE_CLIENT_ID'     => $_ENV['GOOGLE_CLIENT_ID']     ?? 'YOUR_CLIENT_ID.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? 'YOUR_CLIENT_SECRET',
    'GOOGLE_API_KEY'       => $_ENV['GOOGLE_API_KEY']       ?? 'YOUR_GOOGLE_API_KEY',
    'GOOGLE_CALENDAR_ID'   => $_ENV['GOOGLE_CALENDAR_ID']   ?? 'YOUR_CALENDAR_ID@group.calendar.google.com',
];

// Location used for weather forecast
define('WEATHER_LAT',  -27.470125);          
define('WEATHER_LON',  153.021072);          
define('WEATHER_CITY', 'Brisbane, QLD'); // Displayed in the page header
define('WEATHER_TZ',   'Australia/Brisbane'); // Timezone for the Google Calendar

// Database helper
function db(): PDO {
    static $pdo;
    global $CONFIG;
    if (!$pdo) {
        $dsn = 'mysql:host=' . $CONFIG['DB_HOST'] . ';dbname=' . $CONFIG['DB_NAME'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $CONFIG['DB_USER'], $CONFIG['DB_PASS'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}


/*
// Google OAuth (refresh-token flow)
//
// Expects a google_tokens table:
//   CREATE TABLE google_tokens (
//     id           INT PRIMARY KEY AUTO_INCREMENT,
//     access_token TEXT NOT NULL,
//     refresh_token TEXT NOT NULL,
//     expires_at   DATETIME NOT NULL
//   );
*/
function getGoogleAccessToken(): string {
    global $CONFIG;
    $pdo = db();

    $stmt = $pdo->prepare('SELECT access_token, refresh_token, expires_at FROM google_tokens LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('No Google tokens found.');
    }

    // Token still valid return it directly
    if (strtotime($row['expires_at']) > time() + 60) {
        return $row['access_token'];
    }

    // Token expired, get a new one
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $CONFIG['GOOGLE_CLIENT_ID'],
            'client_secret' => $CONFIG['GOOGLE_CLIENT_SECRET'],
            'refresh_token' => $row['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
    ]);
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200 || !isset($response['access_token'])) {
        throw new RuntimeException('Token refresh failed.');
    }

    $pdo->prepare(
        'UPDATE google_tokens
         SET access_token = :access, expires_at = DATE_ADD(NOW(), INTERVAL :expires SECOND)'
    )->execute([':access' => $response['access_token'], ':expires' => $response['expires_in']]);

    return $response['access_token'];
}

/*
Google Calendar CRUD operations
*/
function googleCalendarRequest(string $method, string $endpoint, ?array $body = null): array {
    $token = getGoogleAccessToken();
    $url   = 'https://www.googleapis.com/calendar/v3' . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['code' => $httpCode, 'data' => $response];
}


function e(mixed $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Google Calendar AJAX handlers (POST requests from the calendar UI)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cal_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['cal_action'];

    if ($action === 'fetch_events') {
        $year     = (int)($_POST['year']  ?? date('Y'));
        $month    = (int)($_POST['month'] ?? date('n'));
        $tz       = new DateTimeZone(WEATHER_TZ);
        $offset   = (new DateTime('now', $tz))->format('P');
        $firstDay = sprintf('%04d-%02d-01T00:00:00%s', $year, $month, $offset);
        $lastDay  = date('Y-m-t\T23:59:59', mktime(0, 0, 0, $month, 1, $year)) . $offset;

        $result = googleCalendarRequest('GET', '/calendars/' . $CONFIG['GOOGLE_CALENDAR_ID'] . '/events?' . http_build_query([
            'timeMin'      => $firstDay,
            'timeMax'      => $lastDay,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => 500,
        ]));
        echo json_encode($result);
        exit;
    }

    if ($action === 'create_event') {
        $body = [
            'summary'     => $_POST['summary']     ?? '',
            'description' => $_POST['description'] ?? '',
            'start'       => ['dateTime' => $_POST['start_datetime'], 'timeZone' => WEATHER_TZ],
            'end'         => ['dateTime' => $_POST['end_datetime'],   'timeZone' => WEATHER_TZ],
        ];
        if (!empty($_POST['all_day'])) {
            $body['start'] = ['date' => $_POST['start_date']];
            $body['end']   = ['date' => $_POST['end_date']];
        }
        echo json_encode(googleCalendarRequest('POST', '/calendars/' . $CONFIG['GOOGLE_CALENDAR_ID'] . '/events', $body));
        exit;
    }

    if ($action === 'update_event') {
        $eventId = $_POST['event_id'] ?? '';
        $body = [
            'summary'     => $_POST['summary']     ?? '',
            'description' => $_POST['description'] ?? '',
            'start'       => ['dateTime' => $_POST['start_datetime'], 'timeZone' => WEATHER_TZ],
            'end'         => ['dateTime' => $_POST['end_datetime'],   'timeZone' => WEATHER_TZ],
        ];
        if (!empty($_POST['all_day'])) {
            $body['start'] = ['date' => $_POST['start_date']];
            $body['end']   = ['date' => $_POST['end_date']];
        }
        echo json_encode(googleCalendarRequest('PUT', '/calendars/' . $CONFIG['GOOGLE_CALENDAR_ID'] . '/events/' . urlencode($eventId), $body));
        exit;
    }

    if ($action === 'delete_event') {
        $eventId = $_POST['event_id'] ?? '';
        if ($eventId === '') {
            echo json_encode(['code' => 400, 'data' => ['error' => 'Missing event_id']]);
            exit;
        }
        echo json_encode(googleCalendarRequest('DELETE', '/calendars/' . $CONFIG['GOOGLE_CALENDAR_ID'] . '/events/' . urlencode($eventId)));
        exit;
    }

    echo json_encode(['code' => 400, 'data' => ['error' => 'Unknown action']]);
    exit;
}

date_default_timezone_set(WEATHER_TZ);

/**
 * Fetch 7-day daily forecast from the Google Weather API.
 */
function fetchWeatherForecast(): ?array {
    global $CONFIG;
    $url = 'https://weather.googleapis.com/v1/forecast/days:lookup'
         . '?key=' . $CONFIG['GOOGLE_API_KEY']
         . '&location.latitude='  . WEATHER_LAT
         . '&location.longitude=' . WEATHER_LON
         . '&days=7&pageSize=7';

    $ctx      = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!$data || !isset($data['forecastDays'])) return null;

    $daily = [];
    foreach ($data['forecastDays'] as $day) {
        $dateObj = $day['displayDate'] ?? $day['date'] ?? null;
        if (!$dateObj) continue;

        $date    = sprintf('%04d-%02d-%02d', $dateObj['year'], $dateObj['month'], $dateObj['day']);
        $daytime = $day['daytimeForecast']   ?? [];
        $night   = $day['nighttimeForecast'] ?? [];
        $condType = $daytime['weatherCondition']['type'] ?? ($night['weatherCondition']['type'] ?? 'UNKNOWN');

        $daily[] = [
            'date'       => $date,
            'code'       => $condType,
            'temp_max'   => round($day['maxTemperature']['degrees']              ?? 0),
            'temp_min'   => round($day['minTemperature']['degrees']              ?? 0),
            'wind'       => round($daytime['wind']['speed']['value']             ?? 0),
            'precip_mm'  => round($daytime['precipitation']['qpf']['quantity']  ?? 0, 1),
            'precip_pct' => $daytime['precipitation']['probability']['percent'] ?? 0,
        ];
    }
    return $daily;
}

/**
 * Fetch 7 days of hourly forecasts (7 pages × 24 hours) using pagination.
 */
function fetchAllForecasts(): array {
    global $CONFIG;
    $baseUrl = 'https://weather.googleapis.com/v1/forecast/hours:lookup'
             . '?key=' . $CONFIG['GOOGLE_API_KEY']
             . '&location.latitude='  . WEATHER_LAT
             . '&location.longitude=' . WEATHER_LON
             . '&pageSize=24';

    $ctx     = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $hourly  = [];
    $url     = $baseUrl;
    $maxPages = 7; // 7 pages × 24 hours = 168 hours (7 days)

    //We have to loop as current API limits range to 24 hrs
    for ($page = 0; $page < $maxPages; $page++) {
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) break;

        $data = json_decode($response, true);
        if (!$data || !isset($data['forecastHours'])) break;

        foreach ($data['forecastHours'] as $hour) {
            $dt = $hour['interval']['startTime'] ?? '';
            if (!$dt) continue;
            $ts = strtotime($dt);
            if ($ts === false) continue;

            $key = date('Y-m-d H', $ts);
            $hourly[$key] = [
                'code'     => $hour['weatherCondition']['type'] ?? 'UNKNOWN',
                'temp'     => round($hour['temperature']['degrees']  ?? 0),
                'humidity' => $hour['relativeHumidity']              ?? 0,
                'wind'     => round($hour['wind']['speed']['value']  ?? 0),
            ];
        }

        if (!empty($data['nextPageToken'])) {
            $url = $baseUrl . '&pageToken=' . urlencode($data['nextPageToken']);
        } else {
            break;
        }
    }
    return $hourly;
}

/**
 * Map a Google Weather condition type string to one of 8 CSS animation types.
 */
function getWeatherType(string $conditionType): string {
    $map = [
        'CLEAR'                   => 'sunny',
        'MOSTLY_CLEAR'            => 'sunny',
        'PARTLY_CLOUDY'           => 'partly-cloudy',
        'MOSTLY_CLOUDY'           => 'cloudy',
        'CLOUDY'                  => 'cloudy',
        'OVERCAST'                => 'cloudy',
        'FOG'                     => 'fog',
        'LIGHT_FOG'               => 'fog',
        'DRIZZLE'                 => 'drizzle',
        'LIGHT_RAIN'              => 'drizzle',
        'RAIN'                    => 'rain',
        'HEAVY_RAIN'              => 'rain',
        'SCATTERED_SHOWERS'       => 'rain',
        'SHOWERS'                 => 'rain',
        'LIGHT_SNOW'              => 'snow',
        'SNOW'                    => 'snow',
        'HEAVY_SNOW'              => 'snow',
        'SNOW_SHOWERS'            => 'snow',
        'BLIZZARD'                => 'snow',
        'ICE_PELLETS'             => 'snow',
        'FREEZING_RAIN'           => 'rain',
        'FREEZING_DRIZZLE'        => 'drizzle',
        'THUNDERSTORM'            => 'storm',
        'THUNDERSHOWER'           => 'storm',
        'SCATTERED_THUNDERSTORMS' => 'storm',
        'HAIL'                    => 'storm',
        'TORNADO'                 => 'storm',
        'TROPICAL_STORM'          => 'storm',
        'HURRICANE'               => 'storm',
        'WINDY'                   => 'partly-cloudy',
        'HAZE'                    => 'fog',
        'SMOKE'                   => 'fog',
        'DUST'                    => 'fog',
        'SAND'                    => 'fog',
    ];
    return $map[$conditionType] ?? 'partly-cloudy';
}

/**
 * Friendly description for a Google Weather condition type.
 */
function getWeatherDescription(string $conditionType): string {
    $descriptions = [
        'CLEAR'                   => 'Clear sky',
        'MOSTLY_CLEAR'            => 'Mostly clear',
        'PARTLY_CLOUDY'           => 'Partly cloudy',
        'MOSTLY_CLOUDY'           => 'Mostly cloudy',
        'CLOUDY'                  => 'Cloudy',
        'OVERCAST'                => 'Overcast',
        'FOG'                     => 'Fog',
        'LIGHT_FOG'               => 'Light fog',
        'DRIZZLE'                 => 'Drizzle',
        'LIGHT_RAIN'              => 'Light rain',
        'RAIN'                    => 'Rain',
        'HEAVY_RAIN'              => 'Heavy rain',
        'SCATTERED_SHOWERS'       => 'Scattered showers',
        'SHOWERS'                 => 'Showers',
        'LIGHT_SNOW'              => 'Light snow',
        'SNOW'                    => 'Snow',
        'HEAVY_SNOW'              => 'Heavy snow',
        'SNOW_SHOWERS'            => 'Snow showers',
        'BLIZZARD'                => 'Blizzard',
        'ICE_PELLETS'             => 'Ice pellets',
        'FREEZING_RAIN'           => 'Freezing rain',
        'FREEZING_DRIZZLE'        => 'Freezing drizzle',
        'THUNDERSTORM'            => 'Thunderstorm',
        'THUNDERSHOWER'           => 'Thundershower',
        'SCATTERED_THUNDERSTORMS' => 'Scattered storms',
        'HAIL'                    => 'Hail',
        'TORNADO'                 => 'Tornado',
        'TROPICAL_STORM'          => 'Tropical storm',
        'HURRICANE'               => 'Hurricane',
        'WINDY'                   => 'Windy',
        'HAZE'                    => 'Haze',
        'SMOKE'                   => 'Smoke',
        'DUST'                    => 'Dusty',
        'SAND'                    => 'Sandy',
    ];
    return $descriptions[$conditionType] ?? ucwords(strtolower(str_replace('_', ' ', $conditionType)));
}

function getWeatherEmoji(string $conditionType): string {
    $map = [
        'sunny'         => "\u{2600}\u{FE0F}",
        'partly-cloudy' => "\u{26C5}",
        'cloudy'        => "\u{2601}\u{FE0F}",
        'fog'           => "\u{1F32B}\u{FE0F}",
        'drizzle'       => "\u{1F326}\u{FE0F}",
        'rain'          => "\u{1F327}\u{FE0F}",
        'snow'          => "\u{2744}\u{FE0F}",
        'storm'         => "\u{26C8}\u{FE0F}",
    ];
    return $map[getWeatherType($conditionType)] ?? "\u{26C5}";
}

/**
 * Find the closest hourly weather entry to a given date + time string for a job.
 */
function getClosestWeather(array $hourlyWeather, string $date, string $time): ?array {
    if (empty($time)) $time = '09:00';
    $hour = (int)substr($time, 0, 2);
    $key  = $date . ' ' . str_pad($hour, 2, '0', STR_PAD_LEFT);
    if (isset($hourlyWeather[$key])) return $hourlyWeather[$key];

    for ($offset = 1; $offset <= 2; $offset++) {
        $after  = $date . ' ' . str_pad($hour + $offset, 2, '0', STR_PAD_LEFT);
        $before = $date . ' ' . str_pad(max(0, $hour - $offset), 2, '0', STR_PAD_LEFT);
        if (isset($hourlyWeather[$after]))  return $hourlyWeather[$after];
        if (isset($hourlyWeather[$before])) return $hourlyWeather[$before];
    }
    return null;
}

/**
 * Format a job's address from the joined customer_addresses columns.
 * Adjust field names to match your own schema.
 */
function formatJobAddress(array $job): string {
    $parts = array_filter([
        $job['unit_number']   ? 'U' . $job['unit_number'] : '',
        trim(($job['street_number'] ?? '') . ' ' . ($job['street_name'] ?? '')),
        $job['suburb']   ?? '',
        $job['state']    ?? '',
        $job['postcode'] ?? '',
    ]);
    return implode(', ', $parts);
}

//Let's go!
$pdo          = db();
$weather      = fetchWeatherForecast();
$hourlyWeather = fetchAllForecasts();

$today   = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+7 days'));

// Jobs query — adjust table/column names to match your schema.
// The subquery resolves one address per customer (the most recently added)
// before joining, so the outer query requires no GROUP BY.
$stmt = $pdo->prepare('
    SELECT
        j.job_id,
        j.job_date,
        j.job_time,
        j.description,
        j.quoted_total,
        j.final_total,
        j.notes,
        j.status_id,
        c.first_name  AS customer_first,
        c.last_name   AS customer_last,
        c.phone       AS customer_phone,
        c.email       AS customer_email,
        ca.unit_number,
        ca.street_number,
        ca.street_name,
        ca.suburb,
        ca.postcode,
        ca.state
    FROM jobs j
    LEFT JOIN customers c ON c.customer_id = j.customer_id
    LEFT JOIN (
        SELECT *
        FROM customer_addresses
        WHERE id IN (
            SELECT MAX(id)
            FROM customer_addresses
            GROUP BY customer_id
        )
    ) ca ON ca.customer_id = j.customer_id
    WHERE j.job_date BETWEEN :start AND :end
    ORDER BY j.job_date ASC, j.job_time ASC
');
$stmt->execute(['start' => $today, 'end' => $endDate]);
$jobs = $stmt->fetchAll();

// Group jobs by date
$jobsByDate = [];
foreach ($jobs as $job) {
    $jobsByDate[$job['job_date']][] = $job;
}

$statusLabels = [
    1 => ['label' => 'Scheduled',   'color' => '#4f92ff'],
    2 => ['label' => 'In Progress', 'color' => '#ffb739'],
    3 => ['label' => 'Completed',   'color' => '#31dda4'],
    4 => ['label' => 'Cancelled',   'color' => '#f75858'],
    5 => ['label' => 'On Hold',     'color' => '#9f76ff'],
];

// Job counts per date (used for the badge on weather cards)
$jobCounts = [];
for ($d = 0; $d < 7; $d++) {
    $dt = date('Y-m-d', strtotime("+$d days"));
    $jobCounts[$dt] = count($jobsByDate[$dt] ?? []);
}

// Build hourly data grouped by date for the JavaScript layer
$hourlyByDate = [];
foreach ($hourlyWeather as $key => $hw) {
    [$dt, $hr] = explode(' ', $key);
    $hourlyByDate[$dt][] = [
        'hour'  => (int)$hr,
        'label' => date('g A', strtotime("$hr:00")),
        'temp'  => $hw['temp'],
        'wind'  => $hw['wind'],
        'code'  => $hw['code'],
        'emoji' => getWeatherEmoji($hw['code']),
        'desc'  => getWeatherDescription($hw['code']),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upcoming 7 Days</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard { max-width: 1400px; margin: 0 auto; padding: 2rem; }

        .header { text-align: center; margin-bottom: 2.5rem; }
        .header h1 {
            font-size: 2.2rem; font-weight: 700;
            background: linear-gradient(135deg, #0284c7, #6366f1, #059669);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-size: 200% 200%;
            animation: gradientShift 5s ease infinite;
        }
        .header p { color: #64748b; font-size: 0.95rem; margin-top: 0.25rem; }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50%       { background-position: 100% 50%; }
        }

        .section-title {
            font-size: 1.25rem; font-weight: 600; margin-bottom: 1.25rem;
            display: flex; align-items: center; gap: 0.5rem;
            padding-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }
        .section-title .icon { font-size: 1.4rem; }

        .weather-strip {
            display: grid; grid-template-columns: repeat(7, 1fr);
            gap: 1rem; margin-bottom: 3rem;
        }

        .weather-card {
            background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 1rem;
            padding: 1.25rem 1rem; text-align: center;
            position: relative; overflow: hidden; cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: fadeSlideUp 0.5s ease both;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        /* Photo background (top 58% of card) */
        .weather-card::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 58%; border-radius: 1rem 1rem 0 0;
            z-index: 0; pointer-events: none;
            background-size: cover; background-position: center;
        }
        /* Fade overlay so text below stays readable */
        .weather-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 58%; border-radius: 1rem 1rem 0 0;
            z-index: 1; pointer-events: none;
            background: linear-gradient(180deg, transparent 30%, rgba(255,255,255,0.6) 70%, #fff 100%);
        }
        .weather-card > * { position: relative; z-index: 2; }

        /*
         * Weather photo backgrounds.
         * Place matching images in assets/images/ (sunny.jpg, cloudy.jpg, etc.)
         * or swap these for Unsplash source URLs:
         * e.g. url('https://source.unsplash.com/400x250/?sunny+sky')
         */
        .weather-card.wt-sunny::after        { background-image: url('assets/images/sunny.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-partly-cloudy::after{ background-image: url('assets/images/partly-cloudy.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-cloudy::after       { background-image: url('assets/images/cloudy.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-rain::after         { background-image: url('assets/images/rain.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-drizzle::after      { background-image: url('assets/images/drizzle.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-storm::after        { background-image: url('assets/images/storm.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-snow::after         { background-image: url('assets/images/snow.jpg?w=400&h=250&fit=crop&q=60'); }
        .weather-card.wt-fog::after          { background-image: url('assets/images/fog.jpg?w=400&h=250&fit=crop&q=60'); }

        .weather-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.1);
        }
        .weather-card.selected {
            border-color: #0284c7;
            box-shadow: 0 0 20px rgba(2,132,199,0.15);
            transform: translateY(-2px);
        }
        /* Blue accent bar at bottom of selected card */
        .weather-card.selected::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0;
            height: 3px; border-radius: 0 0 1rem 1rem;
            background: linear-gradient(90deg, #0284c7, #6366f1);
            z-index: 3;
        }

        /* Job count badge */
        .weather-job-count {
            margin-top: 0.6rem; font-size: 0.7rem; font-weight: 600; color: #64748b;
        }
        .weather-job-count span {
            background: #0284c7; color: #fff;
            padding: 0.1rem 0.45rem; border-radius: 0.75rem;
            font-size: 0.65rem; margin-left: 0.2rem;
        }
        .weather-job-count.no-jobs-count { color: #cbd5e1; }

        .hourly-section { margin-bottom: 2rem; animation: fadeSlideUp 0.4s ease both; }
        .hourly-scroll {
            display: flex; gap: 0.5rem; overflow-x: auto;
            padding: 0.75rem 0.25rem; scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
        }
        .hourly-scroll::-webkit-scrollbar { height: 4px; }
        .hourly-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .hourly-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .hourly-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .hourly-item {
            flex: 0 0 auto; width: 72px;
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 0.75rem; padding: 0.6rem 0.4rem;
            text-align: center; transition: all 0.2s ease;
        }
        .hourly-item:hover { border-color: #0284c7; background: #f0f9ff; }
        .hourly-item.current-hour {
            border-color: #0284c7; background: #eff6ff;
            box-shadow: 0 0 10px rgba(2,132,199,0.1);
        }
        .hourly-time { font-size: 0.65rem; font-weight: 600; color: #64748b; margin-bottom: 0.3rem; }
        .hourly-icon { font-size: 1.1rem; margin-bottom: 0.2rem; }
        .hourly-temp { font-size: 0.85rem; font-weight: 700; color: #0f172a; }
        .hourly-wind { font-size: 0.6rem; color: #94a3b8; margin-top: 0.15rem; }
        .hourly-label {
            font-size: 0.85rem; font-weight: 600; color: #64748b;
            margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;
        }

        /* Card text */
        .weather-day {
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: #fff; margin-bottom: 0.25rem;
            text-shadow: 0 1px 1px rgba(0,0,0,0.5);
        }
        /* Softer text for light-background weather types */
        .wt-drizzle .weather-day, .wt-drizzle .weather-date,
        .wt-cloudy  .weather-day, .wt-cloudy  .weather-date,
        .wt-fog     .weather-day, .wt-fog     .weather-date {
            color: #565656; text-shadow: none; font-weight: bold;
        }
        .weather-date {
            font-size: 0.8rem; color: #f1f5f9; margin-bottom: 1rem;
            text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }
        .weather-animation { width: 80px; height: 80px; margin: 0 auto 1rem; position: relative; }
        .weather-temp { font-size: 1.75rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; }
        .weather-desc { font-size: 0.75rem; color: #64748b; text-transform: capitalize; }
        .weather-details {
            margin-top: 0.75rem; display: flex; justify-content: center;
            gap: 0.75rem; font-size: 0.7rem; color: #94a3b8;
        }

        /* Sun */
        .sun {
            width: 50px; height: 50px; background: #fbbf24; border-radius: 50%;
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 0 30px #fbbf24, 0 0 60px rgba(251,191,36,0.3);
            animation: pulse 3s ease-in-out infinite;
        }
        .sun-ray {
            position: absolute; top: 50%; left: 0;
            width: 80px; height: 3px;
            background: linear-gradient(90deg, transparent, #fbbf24, transparent);
            transform-origin: center; animation: rotateSun 10s linear infinite;
        }
        .sun-ray:nth-child(2) { animation-delay: -1.25s; }
        .sun-ray:nth-child(3) { animation-delay: -2.5s; }
        .sun-ray:nth-child(4) { animation-delay: -3.75s; }
        .sun-ray:nth-child(5) { animation-delay: -5s; }
        .sun-ray:nth-child(6) { animation-delay: -6.25s; }
        .sun-ray:nth-child(7) { animation-delay: -7.5s; }
        .sun-ray:nth-child(8) { animation-delay: -8.75s; }
        @keyframes rotateSun { to { transform: rotate(360deg); } }

        /* Cloud */
        .cloud { position: absolute; background: #cbd5e1; border-radius: 50px; animation: floatCloud 4s ease-in-out infinite; }
        .cloud-main { width: 60px; height: 24px; top: 40%; left: 50%; transform: translateX(-50%); }
        .cloud-main::before {
            content: ''; position: absolute; width: 28px; height: 28px;
            background: #cbd5e1; border-radius: 50%; top: -16px; left: 12px;
        }
        .cloud-main::after {
            content: ''; position: absolute; width: 20px; height: 20px;
            background: #cbd5e1; border-radius: 50%; top: -10px; left: 32px;
        }
        .storm .cloud-main,
        .storm .cloud-main::before,
        .storm .cloud-main::after { background: #64748b; }
        @keyframes floatCloud {
            0%, 100% { transform: translateX(-50%) translateY(0); }
            50%       { transform: translateX(-50%) translateY(-4px); }
        }

        /* Rain */
        .rain-drop {
            position: absolute; width: 4px; height: 12px;
            background: linear-gradient(to bottom, transparent, #005bc0);
            border-radius: 2px; animation: rainFall 1s linear infinite;
        }
        .rain-drop:nth-child(2) { left: 30%; animation-delay: 0.2s; height: 10px; }
        .rain-drop:nth-child(3) { left: 50%; animation-delay: 0.5s; height: 14px; }
        .rain-drop:nth-child(4) { left: 70%; animation-delay: 0.3s; height: 8px; }
        .rain-drop:nth-child(5) { left: 45%; animation-delay: 0.7s; height: 11px; }
        @keyframes rainFall {
            0%   { top: 60%; opacity: 0; }
            20%  { opacity: 1; }
            100% { top: 100%; opacity: 0; }
        }

        /* Drizzle */
        .drizzle-drop {
            position: absolute; width: 1.5px; height: 6px;
            background: linear-gradient(to bottom, transparent, #005bc0);
            border-radius: 2px; animation: rainFall 1.5s linear infinite;
        }
        .drizzle-drop:nth-child(2) { left: 35%; animation-delay: 0.3s; }
        .drizzle-drop:nth-child(3) { left: 55%; animation-delay: 0.8s; }
        .drizzle-drop:nth-child(4) { left: 65%; animation-delay: 0.5s; }

        /* Lightning */
        .lightning {
            position: absolute; top: 55%; left: 50%; transform: translateX(-50%);
            width: 0; height: 0;
            border-left: 4px solid transparent; border-right: 4px solid transparent;
            border-top: 20px solid #fbbf24;
            opacity: 0; animation: flash 3s ease-in-out infinite;
            filter: drop-shadow(0 0 6px #fbbf24);
        }
        .lightning::after {
            content: ''; position: absolute; top: -6px; left: -1px;
            width: 0; height: 0;
            border-left: 3px solid transparent; border-right: 3px solid transparent;
            border-top: 14px solid #fbbf24; transform: rotate(15deg);
        }
        @keyframes flash {
            0%, 90%, 100% { opacity: 0; }
            92%, 94%      { opacity: 1; }
            93%           { opacity: 0.3; }
        }

        /* Snow */
        .snowflake {
            position: absolute; width: 6px; height: 6px;
            background: #e2e8f0; border-radius: 50%;
            animation: snowFall 2.5s linear infinite;
        }
        .snowflake:nth-child(2) { left: 30%; animation-delay: 0.5s; width: 4px; height: 4px; }
        .snowflake:nth-child(3) { left: 55%; animation-delay: 1s;   width: 5px; height: 5px; }
        .snowflake:nth-child(4) { left: 70%; animation-delay: 1.5s; width: 3px; height: 3px; }
        @keyframes snowFall {
            0%   { top: 55%; opacity: 0; transform: translateX(0); }
            20%  { opacity: 1; }
            100% { top: 100%; opacity: 0; transform: translateX(10px); }
        }

        /* Fog */
        .fog-line {
            position: absolute; height: 3px;
            background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
            border-radius: 3px; animation: fogDrift 4s ease-in-out infinite;
        }
        .fog-line:nth-child(1) { top: 40%; left: 10%; width: 60%; }
        .fog-line:nth-child(2) { top: 55%; left: 20%; width: 50%; animation-delay: 1s; }
        .fog-line:nth-child(3) { top: 70%; left:  5%; width: 65%; animation-delay: 2s; }
        @keyframes fogDrift {
            0%, 100% { transform: translateX(0); opacity: 0.5; }
            50%       { transform: translateX(8px); opacity: 1; }
        }

        /* Partly cloudy */
        .mini-sun {
            width: 30px; height: 30px; background: #fbbf24; border-radius: 50%;
            position: absolute; top: 20%; right: 15%;
            box-shadow: 0 0 15px #fbbf24; animation: pulse 3s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 20px rgba(251,191,36,0.4); }
            50%       { box-shadow: 0 0 35px rgba(251,191,36,0.7); }
        }

        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .jobs-section { margin-bottom: 3rem; }
        .day-group    { margin-bottom: 1.5rem; }
        .jobs-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 0.75rem;
        }

        .job-card {
            background: #fff;
            border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .job-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }

        .job-card-header {
            display: flex; justify-content: space-between;
            align-items: flex-start; margin-bottom: 0.75rem;
        }
        .job-customer { font-weight: 600; font-size: 1rem; color: #0f172a; }
        .job-status {
            font-size: 0.7rem; font-weight: 600; padding: 0.2rem 0.6rem;
            border-radius: 1rem; text-transform: uppercase; letter-spacing: 0.03em;
            white-space: nowrap;
        }
        .job-meta {
            display: flex; flex-wrap: wrap; gap: 1rem;
            margin-bottom: 0.75rem; font-size: 0.85rem; color: #64748b;
        }
        .job-meta-item { display: flex; align-items: center; gap: 0.3rem; }
        .job-description { font-size: 0.85rem; color: #475569; margin-bottom: 0.75rem; line-height: 1.5; }
        .job-address { font-size: 0.8rem; color: #94a3b8; display: flex; align-items: flex-start; gap: 0.35rem; }
        .job-weather-badge {
            display: inline-flex; align-items: center; gap: 0.3rem;
            background: #f8fafc; border: 1px solid #e2e8f0;
            padding: 0.2rem 0.6rem; border-radius: 1rem;
            font-size: 0.75rem; color: #64748b; margin-top: 0.5rem;
        }
        .job-totals {
            display: flex; gap: 1rem; margin-top: 0.75rem;
            padding-top: 0.75rem; border-top: 1px solid #f1f5f9; font-size: 0.8rem;
        }
        .job-total-item { color: #94a3b8; }
        .job-total-item strong { color: #0f172a; font-weight: 600; }
        .job-notes { margin-top: 0.5rem; font-size: 0.75rem; color: #94a3b8; font-style: italic; }

        /* Weather-based job card highlights */
        .job-card.rain-warning  { border-color: #93c5fd; background: #f0f9ff; }
        .job-card.storm-warning { border-color: #fca5a5; background: #fef2f2; box-shadow: 0 0 15px rgba(239,68,68,0.08); }

        .no-jobs {
            color: #94a3b8; font-size: 0.9rem; font-style: italic;
            padding: 1rem; text-align: center;
            background: #fff; border-radius: 0.75rem; border: 1px dashed #e2e8f0;
        }

        .calendar-section { margin-bottom: 2rem; }
        .calendar-wrapper {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 1rem; overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .cal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 0.5rem;
        }
        .cal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 600; color: #1e293b; }
        .cal-nav { display: flex; gap: 0.5rem; align-items: center; }
        .cal-nav button {
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 0.5rem;
            padding: 0.4rem 0.75rem; cursor: pointer; font-size: 0.85rem; color: #334155;
            transition: all 0.15s;
        }
        .cal-nav button:hover { background: #e2e8f0; }
        .cal-nav .cal-today-btn { background: #0284c7; color: #fff; border-color: #0284c7; }
        .cal-nav .cal-today-btn:hover { background: #0369a1; }
        .cal-add-btn {
            background: #10b981; color: #fff; border: none; border-radius: 0.5rem;
            padding: 0.4rem 0.9rem; cursor: pointer; font-size: 0.85rem; font-weight: 500;
            transition: all 0.15s;
        }
        .cal-add-btn:hover { background: #059669; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
        .cal-dow {
            padding: 0.5rem 0.25rem; text-align: center; font-size: 0.75rem;
            font-weight: 600; color: #64748b; text-transform: uppercase;
            background: #f8fafc; border-bottom: 1px solid #e2e8f0;
            min-width: 0;
        }
        .cal-day {
            min-height: 90px; padding: 0.35rem; border-right: 1px solid #f1f5f9;
            border-bottom: 1px solid #f1f5f9; position: relative; cursor: pointer;
            transition: background 0.15s; min-width: 0;
        }
        .cal-day:hover { background: #f8fafc; }
        .cal-day:nth-child(7n) { border-right: none; }
        .cal-day.other-month { background: #fafbfc; }
        .cal-day.other-month .cal-date { color: #cbd5e1; }
        .cal-day.today { background: #eff6ff; }
        .cal-day.today .cal-date {
            background: #0284c7; color: #fff; border-radius: 50%;
            width: 1.6rem; height: 1.6rem; display: flex; align-items: center; justify-content: center;
        }
        .cal-day-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.2rem; }
        .cal-date {
            font-size: 0.8rem; font-weight: 500; color: #334155;
            width: 1.6rem; height: 1.6rem; display: flex; align-items: center; justify-content: center;
        }
        .cal-weather { font-size: 0.6rem; color: #64748b; white-space: nowrap; line-height: 1; }
        .cal-event {
            font-size: 0.7rem; padding: 0.15rem 0.35rem; margin-bottom: 0.15rem;
            border-radius: 0.25rem; background: #dbeafe; color: #1e40af;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            cursor: pointer; transition: filter 0.15s; line-height: 1.3;
        }
        .cal-event:hover { filter: brightness(0.92); }
        .cal-event.all-day { background: #0284c7; color: #fff; }
        .cal-more { font-size: 0.65rem; color: #64748b; padding: 0.1rem 0.35rem; cursor: pointer; }
        .cal-more:hover { color: #0284c7; }
        .cal-loading { display: flex; align-items: center; justify-content: center; padding: 4rem; color: #94a3b8; font-size: 0.9rem; }

        /* Calendar event modal */
        .cal-modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 1000; align-items: center; justify-content: center;
        }
        .cal-modal-overlay.active { display: flex; }
        .cal-modal {
            background: #fff; border-radius: 1rem; width: 95%; max-width: 480px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden;
            animation: calModalIn 0.2s ease-out;
        }
        @keyframes calModalIn {
            from { transform: translateY(20px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .cal-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0;
        }
        .cal-modal-header h3 { margin: 0; font-size: 1rem; font-weight: 600; }
        .cal-modal-close { background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #94a3b8; padding: 0.25rem; }
        .cal-modal-close:hover { color: #334155; }
        .cal-modal-body { padding: 1.25rem; }
        .cal-modal-body label { display: block; font-size: 0.8rem; font-weight: 500; color: #475569; margin-bottom: 0.3rem; }
        .cal-modal-body input,
        .cal-modal-body textarea,
        .cal-modal-body select {
            width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db;
            border-radius: 0.5rem; font-size: 0.85rem; margin-bottom: 0.85rem;
            font-family: inherit; box-sizing: border-box;
        }
        .cal-modal-body textarea { resize: vertical; min-height: 60px; }
        .cal-modal-body input:focus,
        .cal-modal-body textarea:focus { outline: none; border-color: #0284c7; box-shadow: 0 0 0 2px rgba(2,132,199,0.15); }
        .cal-allday-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.85rem; }
        .cal-allday-row input[type="checkbox"] { width: auto; margin: 0; }
        .cal-allday-row label { margin: 0; }
        .cal-modal-footer {
            display: flex; gap: 0.5rem; justify-content: flex-end;
            padding: 1rem 1.25rem; border-top: 1px solid #e2e8f0; background: #f8fafc;
        }
        .cal-btn {
            padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.85rem;
            font-weight: 500; cursor: pointer; border: 1px solid #d1d5db;
            background: #fff; color: #334155; transition: all 0.15s;
        }
        .cal-btn:hover { background: #f1f5f9; }
        .cal-btn-primary { background: #0284c7; color: #fff; border-color: #0284c7; }
        .cal-btn-primary:hover { background: #0369a1; }
        .cal-btn-danger { background: #ef4444; color: #fff; border-color: #ef4444; }
        .cal-btn-danger:hover { background: #dc2626; }
        .cal-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Weather load error */
        .weather-error {
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 1rem;
            padding: 1.5rem; text-align: center; color: #b45309; margin-bottom: 3rem;
        }

        @media (max-width: 1024px) { .weather-strip { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 768px) {
            .dashboard { padding: 1rem; }
            .weather-strip { grid-template-columns: repeat(2, 1fr); }
            .jobs-grid { grid-template-columns: 1fr; }
            .header h1 { font-size: 1.5rem; }
        }
        @media (max-width: 480px) {
            .weather-strip { grid-template-columns: 1fr 1fr; }
            .day-header { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<div class="dashboard">

    <!-- Header -->
    <div class="header">
        <h1>Upcoming Weather</h1>
        <p><?= e(WEATHER_CITY) ?> &mdash; <?= date('l j F Y') ?></p>
    </div>

    <!-- 7-day weather strip -->
    <?php if ($weather): ?>
    <div class="weather-strip">
        <?php foreach ($weather as $i => $day):
            $date        = $day['date'];
            $ts          = strtotime($date);
            $isToday     = ($date === date('Y-m-d'));
            $weatherType = getWeatherType($day['code']);
            $desc        = getWeatherDescription($day['code']);
            $jc          = $jobCounts[$date] ?? 0;
        ?>
        <div class="weather-card wt-<?= $weatherType ?> <?= $isToday ? 'today selected' : '' ?>"
             data-date="<?= $date ?>"
             onclick="selectDay('<?= $date ?>')"
             style="animation-delay: <?= $i * 0.08 ?>s">

            <div class="weather-day"><?= $isToday ? 'Today' : date('D', $ts) ?></div>
            <div class="weather-date"><?= date('j M', $ts) ?></div>

            <!-- Animated weather icon -->
            <div class="weather-animation">
                <?php if ($weatherType === 'sunny'): ?>
                    <div class="sun"></div>
                    <?php for ($r = 0; $r < 8; $r++): ?>
                        <div class="sun-ray" style="transform: rotate(<?= $r * 45 ?>deg);"></div>
                    <?php endfor; ?>
                <?php elseif ($weatherType === 'partly-cloudy'): ?>
                    <div class="mini-sun"></div>
                    <div class="cloud cloud-main"></div>
                <?php elseif ($weatherType === 'cloudy'): ?>
                    <div class="cloud cloud-main"></div>
                <?php elseif ($weatherType === 'rain' || $weatherType === 'drizzle'):
                    $dropClass = $weatherType === 'drizzle' ? 'drizzle-drop' : 'rain-drop'; ?>
                    <div class="cloud cloud-main"></div>
                    <?php for ($d = 0; $d < 5; $d++): ?>
                        <div class="<?= $dropClass ?>" style="left: <?= 20 + $d * 15 ?>%;"></div>
                    <?php endfor; ?>
                <?php elseif ($weatherType === 'storm'): ?>
                    <div class="storm"><div class="cloud cloud-main"></div></div>
                    <div class="lightning"></div>
                    <?php for ($d = 0; $d < 5; $d++): ?>
                        <div class="rain-drop" style="left: <?= 20 + $d * 15 ?>%;"></div>
                    <?php endfor; ?>
                <?php elseif ($weatherType === 'snow'): ?>
                    <div class="cloud cloud-main"></div>
                    <?php for ($s = 0; $s < 4; $s++): ?>
                        <div class="snowflake" style="left: <?= 20 + $s * 18 ?>%;"></div>
                    <?php endfor; ?>
                <?php elseif ($weatherType === 'fog'): ?>
                    <?php for ($f = 0; $f < 3; $f++): ?>
                        <div class="fog-line"></div>
                    <?php endfor; ?>
                <?php endif; ?>
            </div>

            <div class="weather-temp">
                <?= $day['temp_max'] ?>&deg;
                <small style="font-size:0.6em;color:#94a3b8;"><?= $day['temp_min'] ?>&deg;</small>
            </div>
            <div class="weather-desc"><?= e($desc) ?></div>
            <div class="weather-details">
                <span>&#128167; <?= $day['precip_mm'] ?>mm</span>
                <span>&#128168; <?= $day['wind'] ?> km/h</span>
            </div>
            <div class="weather-job-count <?= $jc === 0 ? 'no-jobs-count' : '' ?>">
                <?php if ($jc > 0): ?>
                    <span><?= $jc ?></span> job<?= $jc > 1 ? 's' : '' ?>
                <?php else: ?>
                    No jobs
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Hourly forecast (scrollable) -->
    <div class="hourly-section" id="hourlySection">
        <div class="hourly-label">&#9201; Hourly Forecast &mdash; <span id="hourlyDayLabel"><?= date('l j M') ?></span></div>
        <div class="hourly-scroll" id="hourlyScroll"></div>
    </div>

    <?php else: ?>
    <div class="weather-error">
        &#9888;&#65039; Unable to load weather data from Google Weather API.
        Check that the API is enabled and billing is active on your Google Cloud project.
        <br><small style="color:#92400e;">The page will retry on the next refresh.</small>
    </div>
    <?php endif; ?>

    <!-- Jobs for the selected day -->
    <div class="section-title">
        <span class="icon">&#128203;</span> Jobs &mdash; <span id="jobsDayLabel"><?= date('l j M') ?></span>
    </div>
    <div class="jobs-section" id="jobsSection">
        <?php
        // All 7 days are rendered; JS shows/hides based on the selected weather card.
        for ($d = 0; $d < 7; $d++):
            $date     = date('Y-m-d', strtotime("+$d days"));
            $dayJobs  = $jobsByDate[$date] ?? [];
            $isToday  = ($d === 0);
            $dayWeather = null;
            if ($weather) {
                foreach ($weather as $w) {
                    if ($w['date'] === $date) { $dayWeather = $w; break; }
                }
            }
        ?>
        <div class="day-group" data-jobs-date="<?= $date ?>" style="display: <?= $isToday ? 'block' : 'none' ?>;">
            <?php if (empty($dayJobs)): ?>
                <div class="no-jobs">No jobs scheduled for this day</div>
            <?php else: ?>
                <div class="jobs-grid">
                    <?php foreach ($dayJobs as $job):
                        $status     = $statusLabels[$job['status_id']] ?? ['label' => 'Unknown', 'color' => '#64748b'];
                        $address    = formatJobAddress($job);
                        $jobWeather = getClosestWeather($hourlyWeather, $job['job_date'], $job['job_time']);
                        $cardClass  = 'job-card';
                        if ($jobWeather) {
                            $wType = getWeatherType($jobWeather['code']);
                            if ($wType === 'storm')                         $cardClass .= ' storm-warning';
                            elseif (in_array($wType, ['rain', 'drizzle'])) $cardClass .= ' rain-warning';
                        }
                    ?>
                    <div class="<?= $cardClass ?>">
                        <div class="job-card-header">
                            <div class="job-customer"><?= e($job['customer_first'] . ' ' . ($job['customer_last'] ?? '')) ?></div>
                            <span class="job-status" style="background:<?= $status['color'] ?>22; color:<?= $status['color'] ?>; border:1px solid <?= $status['color'] ?>44;">
                                <?= $status['label'] ?>
                            </span>
                        </div>
                        <div class="job-meta">
                            <?php if (!empty($job['job_time'])): ?>
                            <span class="job-meta-item">&#128336; <?= date('g:i A', strtotime($job['job_time'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($job['customer_phone'])): ?>
                            <span class="job-meta-item">&#128222; <?= e($job['customer_phone']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="job-description"><?= e($job['description']) ?></div>
                        <?php if ($address): ?>
                        <div class="job-address">&#128205; <?= e($address) ?></div>
                        <?php endif; ?>
                        <?php if ($jobWeather): ?>
                        <div class="job-weather-badge">
                            <?= $jobWeather['temp'] ?>&deg; <?= e(getWeatherDescription($jobWeather['code'])) ?> at job time
                        </div>
                        <?php endif; ?>
                        <?php if ($job['quoted_total'] > 0 || $job['final_total'] > 0): ?>
                        <div class="job-totals">
                            <?php if ($job['quoted_total'] > 0): ?>
                            <span class="job-total-item">Quoted: <strong>$<?= number_format($job['quoted_total'], 2) ?></strong></span>
                            <?php endif; ?>
                            <?php if ($job['final_total'] > 0): ?>
                            <span class="job-total-item">Final: <strong>$<?= number_format($job['final_total'], 2) ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($job['notes'])): ?>
                        <div class="job-notes">&#128221; <?= e($job['notes']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Google Calendar (full month view) -->
    <div class="section-title"><span class="icon">&#128197;</span> Monthly Calendar</div>
    <div class="calendar-section">
        <div class="calendar-wrapper">
            <div class="cal-header">
                <div class="cal-nav">
                    <button onclick="calNav(-1)" title="Previous month">&#9664;</button>
                    <button class="cal-today-btn" onclick="calToday()">Today</button>
                    <button onclick="calNav(1)"  title="Next month">&#9654;</button>
                </div>
                <h3 id="calMonthLabel"></h3>
                <button class="cal-add-btn" onclick="calOpenCreate()">+ New Event</button>
            </div>
            <div class="cal-grid" id="calGrid">
                <div class="cal-loading">Loading calendar...</div>
            </div>
        </div>
    </div>

    <!-- Event create/edit modal -->
    <div class="cal-modal-overlay" id="calModalOverlay" onclick="if(event.target===this)calCloseModal()">
        <div class="cal-modal">
            <div class="cal-modal-header">
                <h3 id="calModalTitle">New Event</h3>
                <button class="cal-modal-close" onclick="calCloseModal()">&times;</button>
            </div>
            <div class="cal-modal-body">
                <input type="hidden" id="calEventId">
                <label for="calSummary">Title</label>
                <input type="text" id="calSummary" placeholder="Event title">
                <div class="cal-allday-row">
                    <input type="checkbox" id="calAllDay" onchange="calToggleAllDay()">
                    <label for="calAllDay">All day</label>
                </div>
                <div id="calDateTimeFields">
                    <label for="calStartDT">Start</label>
                    <input type="datetime-local" id="calStartDT">
                    <label for="calEndDT">End</label>
                    <input type="datetime-local" id="calEndDT">
                </div>
                <div id="calDateFields" style="display:none">
                    <label for="calStartDate">Start Date</label>
                    <input type="date" id="calStartDate">
                    <label for="calEndDate">End Date</label>
                    <input type="date" id="calEndDate">
                </div>
                <label for="calDesc">Description</label>
                <textarea id="calDesc" placeholder="Optional description"></textarea>
            </div>
            <div class="cal-modal-footer">
                <button class="cal-btn cal-btn-danger" id="calDeleteBtn" onclick="calDeleteEvent()" style="display:none;margin-right:auto">Delete</button>
                <button class="cal-btn" onclick="calCloseModal()">Cancel</button>
                <button class="cal-btn cal-btn-primary" id="calSaveBtn" onclick="calSaveEvent()">Save</button>
            </div>
        </div>
    </div>

</div><!-- .dashboard -->

<script>
    const hourlyByDate = <?= json_encode($hourlyByDate) ?>;
    const currentHour  = <?= (int)date('G') ?>;
    const todayDate    = '<?= date('Y-m-d') ?>';
    const tzOffset     = '<?= (new DateTime('now', new DateTimeZone(WEATHER_TZ)))->format('P') ?>';

    // 7-day weather summary for calendar cell overlays
    const dailyWeather = <?= json_encode(
        $weather ? array_combine(
            array_column($weather, 'date'),
            array_map(fn($d) => [
                'emoji'    => getWeatherEmoji($d['code']),
                'temp_max' => $d['temp_max'],
                'temp_min' => $d['temp_min'],
                'desc'     => getWeatherDescription($d['code']),
            ], $weather)
        ) : (object)[]
    ) ?>;

    function selectDay(date) {
        document.querySelectorAll('.weather-card').forEach(c => c.classList.remove('selected'));
        document.querySelector(`.weather-card[data-date="${date}"]`)?.classList.add('selected');

        const d      = new Date(date + 'T00:00:00');
        const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const label  = (date === todayDate ? 'Today — ' : '') + days[d.getDay()] + ' ' + d.getDate() + ' ' + months[d.getMonth()];

        document.getElementById('hourlyDayLabel').textContent = label;
        document.getElementById('jobsDayLabel').textContent   = label;
        renderHourly(date);

        document.querySelectorAll('[data-jobs-date]').forEach(g => {
            g.style.display = g.dataset.jobsDate === date ? 'block' : 'none';
        });

        if (date === todayDate) {
            setTimeout(() => {
                document.querySelector('.hourly-item.current-hour')
                    ?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            }, 100);
        }
    }

    function renderHourly(date) {
        const container = document.getElementById('hourlyScroll');
        const hours     = hourlyByDate[date] || [];
        if (!hours.length) {
            container.innerHTML = '<div style="padding:1rem;color:#94a3b8;font-size:0.85rem;">No hourly data available</div>';
            return;
        }
        container.innerHTML = hours.map(h => {
            const isCurrent = (date === todayDate && h.hour === currentHour);
            return `<div class="hourly-item${isCurrent ? ' current-hour' : ''}">
                <div class="hourly-time">${h.label}</div>
                <div class="hourly-icon">${h.emoji}</div>
                <div class="hourly-temp">${h.temp}&deg;</div>
                <div class="hourly-wind">${h.wind} km/h</div>
            </div>`;
        }).join('');
    }

    // Init
    selectDay(todayDate);
    document.querySelectorAll('.weather-card').forEach((c, i) => c.style.animationDelay = `${i * 0.1}s`);

    // Auto-refresh every 30 minutes
    setTimeout(() => location.reload(), 30 * 60 * 1000);

    let calYear   = new Date().getFullYear();
    let calMonth  = new Date().getMonth(); // 0-indexed
    let calEvents = [];
    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const DOWS   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    function calNav(dir) {
        calMonth += dir;
        if (calMonth < 0)  { calMonth = 11; calYear--; }
        if (calMonth > 11) { calMonth = 0;  calYear++; }
        calLoad();
    }

    function calToday() {
        const now = new Date();
        calYear  = now.getFullYear();
        calMonth = now.getMonth();
        calLoad();
    }

    function calLoad() {
        document.getElementById('calMonthLabel').textContent = MONTHS[calMonth] + ' ' + calYear;
        const grid = document.getElementById('calGrid');
        grid.innerHTML = '<div class="cal-loading">Loading...</div>';

        const fd = new FormData();
        fd.append('cal_action', 'fetch_events');
        fd.append('year',  calYear);
        fd.append('month', calMonth + 1);

        fetch(location.pathname, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                calEvents = res.data?.items ?? [];
                calRender();
            })
            .catch(() => {
                grid.innerHTML = '<div class="cal-loading" style="color:#ef4444">Failed to load events</div>';
            });
    }

    function calRender() {
        const grid     = document.getElementById('calGrid');
        const todayStr = new Date().toISOString().slice(0, 10);

        // Convert Sunday-start (0) to Monday-start (0=Mon)
        const firstDay    = new Date(calYear, calMonth, 1).getDay();
        const startOffset = firstDay === 0 ? 6 : firstDay - 1;
        const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
        const daysInPrev  = new Date(calYear, calMonth,     0).getDate();

        // Index events by date
        const eventsByDate = {};
        calEvents.forEach(ev => {
            const startStr = ev.start.date ?? (ev.start.dateTime ?? '').slice(0, 10);
            if (!startStr) return;
            (eventsByDate[startStr] ??= []).push(ev);
        });

        let html = DOWS.map(d => `<div class="cal-dow">${d}</div>`).join('');

        // Filler from previous month
        for (let i = startOffset - 1; i >= 0; i--) {
            html += `<div class="cal-day other-month"><div class="cal-date">${daysInPrev - i}</div></div>`;
        }

        // Current month
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr  = calYear + '-' + String(calMonth + 1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
            const isToday  = dateStr === todayStr;
            const dayEvs   = eventsByDate[dateStr] ?? [];
            const maxShow  = 3;
            const wx       = dailyWeather[dateStr];

            html += `<div class="cal-day${isToday ? ' today' : ''}" onclick="calOpenCreate('${dateStr}')">`;
            html += '<div class="cal-day-header">';
            html += `<div class="cal-date">${d}</div>`;
            if (wx) html += `<div class="cal-weather" title="${wx.desc} ${wx.temp_max}/${wx.temp_min}">${wx.emoji} ${wx.temp_max}</div>`;
            html += '</div>';

            dayEvs.slice(0, maxShow).forEach(ev => {
                const isAllDay = !!ev.start.date;
                const time     = isAllDay ? '' : new Date(ev.start.dateTime).toLocaleTimeString('en-AU', { hour: '2-digit', minute: '2-digit', hour12: false }) + ' ';
                const cls      = isAllDay ? 'cal-event all-day' : 'cal-event';
                const title    = (ev.summary || '(No title)').replace(/"/g, '&quot;');
                html += `<div class="${cls}" onclick="event.stopPropagation();calOpenEdit('${ev.id}')" title="${title}">${time}${ev.summary || '(No title)'}</div>`;
            });

            if (dayEvs.length > maxShow) {
                html += `<div class="cal-more" onclick="event.stopPropagation()">+${dayEvs.length - maxShow} more</div>`;
            }
            html += '</div>';
        }

        // Filler for next month
        const remaining = (startOffset + daysInMonth) % 7;
        for (let i = 1; i <= (remaining ? 7 - remaining : 0); i++) {
            html += `<div class="cal-day other-month"><div class="cal-date">${i}</div></div>`;
        }

        grid.innerHTML = html;
    }

    function calOpenCreate(dateStr) {
        document.getElementById('calModalTitle').textContent  = 'New Event';
        document.getElementById('calEventId').value           = '';
        document.getElementById('calSummary').value           = '';
        document.getElementById('calDesc').value              = '';
        document.getElementById('calAllDay').checked          = false;
        document.getElementById('calDeleteBtn').style.display = 'none';
        document.getElementById('calSaveBtn').textContent     = 'Create';
        calToggleAllDay();

        if (dateStr) {
            const next = new Date(dateStr + 'T00:00:00');
            next.setDate(next.getDate() + 1);
            document.getElementById('calStartDT').value   = dateStr + 'T09:00';
            document.getElementById('calEndDT').value     = dateStr + 'T10:00';
            document.getElementById('calStartDate').value = dateStr;
            document.getElementById('calEndDate').value   = next.toISOString().slice(0, 10);
        } else {
            const now   = new Date();
            const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
            const base  = local.toISOString().slice(0, 11);
            const h     = now.getHours();
            document.getElementById('calStartDT').value   = base + String(h).padStart(2,'0')   + ':00';
            document.getElementById('calEndDT').value     = base + String(h + 1).padStart(2,'0') + ':00';
            document.getElementById('calStartDate').value = local.toISOString().slice(0, 10);
            document.getElementById('calEndDate').value   = local.toISOString().slice(0, 10);
        }
        document.getElementById('calModalOverlay').classList.add('active');
    }

    function calOpenEdit(eventId) {
        const ev = calEvents.find(e => e.id === eventId);
        if (!ev) return;

        document.getElementById('calModalTitle').textContent      = 'Edit Event';
        document.getElementById('calEventId').value               = ev.id;
        document.getElementById('calSummary').value               = ev.summary || '';
        document.getElementById('calDesc').value                  = ev.description || '';
        document.getElementById('calDeleteBtn').style.display     = 'inline-block';
        document.getElementById('calSaveBtn').textContent         = 'Save';

        const isAllDay = !!ev.start.date;
        document.getElementById('calAllDay').checked = isAllDay;
        calToggleAllDay();

        if (isAllDay) {
            document.getElementById('calStartDate').value = ev.start.date;
            document.getElementById('calEndDate').value   = ev.end.date;
        } else {
            document.getElementById('calStartDT').value = (ev.start.dateTime || '').slice(0, 16);
            document.getElementById('calEndDT').value   = (ev.end.dateTime   || '').slice(0, 16);
        }
        document.getElementById('calModalOverlay').classList.add('active');
    }

    function calCloseModal() {
        document.getElementById('calModalOverlay').classList.remove('active');
    }

    function calToggleAllDay() {
        const allDay = document.getElementById('calAllDay').checked;
        document.getElementById('calDateTimeFields').style.display = allDay ? 'none'  : 'block';
        document.getElementById('calDateFields').style.display     = allDay ? 'block' : 'none';
    }

    function calSaveEvent() {
        const btn     = document.getElementById('calSaveBtn');
        const eventId = document.getElementById('calEventId').value;
        const allDay  = document.getElementById('calAllDay').checked;

        btn.disabled    = true;
        btn.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('cal_action',   eventId ? 'update_event' : 'create_event');
        fd.append('summary',      document.getElementById('calSummary').value);
        fd.append('description',  document.getElementById('calDesc').value);
        if (eventId) fd.append('event_id', eventId);

        if (allDay) {
            fd.append('all_day',    '1');
            fd.append('start_date', document.getElementById('calStartDate').value);
            fd.append('end_date',   document.getElementById('calEndDate').value);
        } else {
            fd.append('start_datetime', document.getElementById('calStartDT').value + ':00' + tzOffset);
            fd.append('end_datetime',   document.getElementById('calEndDT').value   + ':00' + tzOffset);
        }

        fetch(location.pathname, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.code >= 200 && res.code < 300) { calCloseModal(); calLoad(); }
                else alert('Error: ' + (res.data?.error?.message || 'Failed to save event'));
            })
            .catch(() => alert('Network error'))
            .finally(() => { btn.disabled = false; btn.textContent = eventId ? 'Save' : 'Create'; });
    }

    function calDeleteEvent() {
        if (!confirm('Delete this event?')) return;
        const btn = document.getElementById('calDeleteBtn');
        btn.disabled    = true;
        btn.textContent = 'Deleting...';

        const fd = new FormData();
        fd.append('cal_action', 'delete_event');
        fd.append('event_id',   document.getElementById('calEventId').value);

        fetch(location.pathname, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.code >= 200 && res.code < 300) { calCloseModal(); calLoad(); }
                else alert('Error: ' + (res.data?.error?.message || 'Failed to delete'));
            })
            .catch(() => alert('Network error'))
            .finally(() => { btn.disabled = false; btn.textContent = 'Delete'; });
    }

    calLoad();
</script>
</body>
</html>
