<?php
// ==================== SECURITY HEADERS ====================
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// ==================== ENVIRONMENT VARIABLES ====================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: die('❌ BOT_TOKEN missing'));
define('OWNER_ID', (int)(getenv('OWNER_ID') ?: 0));
define('ADMIN_ID', (int)(getenv('ADMIN_ID') ?: OWNER_ID));
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: '@EntertainmentTadkaBot');

// ==================== PUBLIC CHANNELS ====================
define('MAIN_CHANNEL_USERNAME', getenv('MAIN_CHANNEL_USERNAME') ?: '@EntertainmentTadka786');
define('MAIN_CHANNEL_ID', getenv('MAIN_CHANNEL_ID') ?: '-1003181705395');

define('SERIAL_CHANNEL_USERNAME', getenv('SERIAL_CHANNEL_USERNAME') ?: '@Entertainment_Tadka_Serial_786');
define('SERIAL_CHANNEL_ID', getenv('SERIAL_CHANNEL_ID') ?: '-1003614546520');

define('THEATER_CHANNEL_USERNAME', getenv('THEATER_CHANNEL_USERNAME') ?: '@threater_print_movies');
define('THEATER_CHANNEL_ID', getenv('THEATER_CHANNEL_ID') ?: '-1002831605258');

define('BACKUP_CHANNEL_USERNAME', getenv('BACKUP_CHANNEL_USERNAME') ?: '@ETBackup');
define('BACKUP_CHANNEL_ID', getenv('BACKUP_CHANNEL_ID') ?: '-1002964109368');

define('REQUEST_CHANNEL_USERNAME', getenv('REQUEST_CHANNEL_USERNAME') ?: '@EntertainmentTadka7860');
define('REQUEST_CHANNEL_ID', getenv('REQUEST_CHANNEL_ID') ?: '-1003083386043');

// ==================== PRIVATE CHANNELS ====================
define('PRIVATE_CHANNEL_1_ID', getenv('PRIVATE_CHANNEL_1_ID') ?: '-1003251791991');
define('PRIVATE_CHANNEL_2_ID', getenv('PRIVATE_CHANNEL_2_ID') ?: '-1002337293281');

// ==================== FILE PATHS ====================
define('DATA_DIR', __DIR__ . '/data/');
define('CHANNELS_DIR', DATA_DIR . 'channels/');
define('BACKUP_DIR', DATA_DIR . 'backup/');
define('LOG_FILE', __DIR__ . '/logs/bot_activity.log');
define('INDEXED_FILE', DATA_DIR . 'indexed_messages.json');
define('REQUESTS_FILE', DATA_DIR . 'movie_requests.json');
define('USERS_FILE', DATA_DIR . 'users.json');
define('STATS_FILE', DATA_DIR . 'bot_stats.json');

// ==================== CHANNEL MAPPING ====================
define('PUBLIC_CHANNELS', [
    'main' => ['id' => MAIN_CHANNEL_ID, 'username' => MAIN_CHANNEL_USERNAME, 'display' => '🍿 Main Channel', 'csv' => 'main_channel.csv', 'public' => true],
    'serial' => ['id' => SERIAL_CHANNEL_ID, 'username' => SERIAL_CHANNEL_USERNAME, 'display' => '📺 Serial Channel', 'csv' => 'serial_channel.csv', 'public' => true],
    'theater' => ['id' => THEATER_CHANNEL_ID, 'username' => THEATER_CHANNEL_USERNAME, 'display' => '🎭 Theater Prints', 'csv' => 'theater_channel.csv', 'public' => true],
    'backup' => ['id' => BACKUP_CHANNEL_ID, 'username' => BACKUP_CHANNEL_USERNAME, 'display' => '📂 Backup Channel', 'csv' => 'backup_channel.csv', 'public' => true],
    'request' => ['id' => REQUEST_CHANNEL_ID, 'username' => REQUEST_CHANNEL_USERNAME, 'display' => '📥 Request Channel', 'csv' => 'request_channel.csv', 'public' => true]
]);

define('PRIVATE_CHANNELS', [
    'private1' => ['id' => PRIVATE_CHANNEL_1_ID, 'display' => 'Internal', 'csv' => 'private_channel_1.csv', 'public' => false],
    'private2' => ['id' => PRIVATE_CHANNEL_2_ID, 'display' => 'Internal', 'csv' => 'private_channel_2.csv', 'public' => false]
]);

// ==================== HELPER FUNCTIONS ====================
function get_channel_type_by_id($channel_id) {
    $channel_id = (string)$channel_id;
    foreach (PUBLIC_CHANNELS as $type => $info) {
        if ($info['id'] == $channel_id) return $type;
    }
    foreach (PRIVATE_CHANNELS as $type => $info) {
        if ($info['id'] == $channel_id) return $type;
    }
    return 'other';
}

function is_public_channel($channel_type) {
    return isset(PUBLIC_CHANNELS[$channel_type]);
}

function get_channel_csv_path($channel_type) {
    if (isset(PUBLIC_CHANNELS[$channel_type])) {
        return CHANNELS_DIR . PUBLIC_CHANNELS[$channel_type]['csv'];
    }
    if (isset(PRIVATE_CHANNELS[$channel_type])) {
        return CHANNELS_DIR . PRIVATE_CHANNELS[$channel_type]['csv'];
    }
    return null;
}

function get_channel_display_name($channel_type, $is_admin = false) {
    if ($is_admin) {
        if (isset(PUBLIC_CHANNELS[$channel_type])) return PUBLIC_CHANNELS[$channel_type]['display'];
        if (isset(PRIVATE_CHANNELS[$channel_type])) return PRIVATE_CHANNELS[$channel_type]['display'];
    } else {
        if (isset(PUBLIC_CHANNELS[$channel_type])) return PUBLIC_CHANNELS[$channel_type]['display'];
    }
    return '📢 Channel';
}

function get_channel_url($channel_type) {
    if (isset(PUBLIC_CHANNELS[$channel_type])) {
        return 'https://t.me/' . ltrim(PUBLIC_CHANNELS[$channel_type]['username'], '@');
    }
    return '';
}

// ==================== INITIALIZE DIRECTORIES & FILES ====================
if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!file_exists(CHANNELS_DIR)) mkdir(CHANNELS_DIR, 0755, true);
if (!file_exists(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
if (!file_exists(dirname(LOG_FILE))) mkdir(dirname(LOG_FILE), 0755, true);

// Create CSV files with headers if not exist
foreach (PUBLIC_CHANNELS as $info) {
    $file = CHANNELS_DIR . $info['csv'];
    if (!file_exists($file)) {
        file_put_contents($file, "movie_name,message_id,channel_id\n");
    }
}
foreach (PRIVATE_CHANNELS as $info) {
    $file = CHANNELS_DIR . $info['csv'];
    if (!file_exists($file)) {
        file_put_contents($file, "movie_name,message_id,channel_id\n");
    }
}

// Create JSON files if not exist
if (!file_exists(INDEXED_FILE)) file_put_contents(INDEXED_FILE, json_encode([]));
if (!file_exists(REQUESTS_FILE)) file_put_contents(REQUESTS_FILE, json_encode(['requests' => [], 'last_id' => 0]));
if (!file_exists(USERS_FILE)) file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
if (!file_exists(STATS_FILE)) file_put_contents(STATS_FILE, json_encode(['total_movies' => 0, 'total_users' => 0, 'total_searches' => 0, 'total_downloads' => 0, 'last_updated' => date('Y-m-d H:i:s')]));

// ==================== CONSTANTS ====================
define('ITEMS_PER_PAGE', 10);
define('MAX_SEARCH_RESULTS', 20);
define('DAILY_REQUEST_LIMIT', 5);
