<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions/utils.php';
require_once __DIR__ . '/functions/telegram.php';
require_once __DIR__ . '/functions/movies.php';
require_once __DIR__ . '/functions/user.php';
require_once __DIR__ . '/functions/backup.php';
require_once __DIR__ . '/handlers/commands.php';
require_once __DIR__ . '/handlers/callbacks.php';
require_once __DIR__ . '/handlers/request_handlers.php';

$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    // Web interface for status
    $stats = get_stats();
    echo "<h1>🤖 Entertainment Tadka Bot</h1>";
    echo "<p>✅ Bot is running</p>";
    echo "<p>🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p>👥 Total Users: " . ($stats['total_users'] ?? 0) . "</p>";
    echo "<p>🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<p>📥 Total Downloads: " . ($stats['total_downloads'] ?? 0) . "</p>";
    echo "<hr>";
    echo "<p>📢 <a href='https://t.me/EntertainmentTadka786'>Main Channel</a> | ";
    echo "<a href='https://t.me/EntertainmentTadka7860'>Request Channel</a></p>";
    exit;
}

// Channel post handling (auto indexing)
if (isset($update['channel_post'])) {
    $post = $update['channel_post'];
    $chat_id = $post['chat']['id'];
    $channel_type = get_channel_type_by_id($chat_id);
    
    if ($channel_type != 'other') {
        auto_index_post($post, $chat_id, $channel_type);
    }
    exit;
}

// Message handling
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = trim($message['text'] ?? '');
    
    // Update user data
    update_user_data($user_id, [
        'first_name' => $message['from']['first_name'] ?? '',
        'username' => $message['from']['username'] ?? ''
    ]);
    
    // Command or search
    if (strpos($text, '/') === 0) {
        $parts = explode(' ', $text);
        $command = strtolower($parts[0]);
        $params = array_slice($parts, 1);
        handle_command($chat_id, $user_id, $command, $params);
    } elseif (!empty($text)) {
        send_typing_action($chat_id);
        $results = search_movies($text);
        if (empty($results)) {
            sendMessage($chat_id, "😔 No movies found for '$text'.\n\nUse /request to request this movie.");
            update_stats('failed_searches', 1);
        } else {
            update_stats('successful_searches', 1);
            update_user_activity($user_id, 'search');
            $msg = "🔍 Found " . count($results) . " results:\n\n";
            $keyboard = ['inline_keyboard' => []];
            $i = 1;
            foreach (array_slice($results, 0, 10) as $movie) {
                $display = get_channel_display_name($movie['channel_type'], false);
                $msg .= "$i. $display " . htmlspecialchars($movie['movie_name']) . "\n";
                $keyboard['inline_keyboard'][] = [['text' => "$i. " . htmlspecialchars($movie['movie_name']), 'callback_data' => json_encode($movie)]];
                $i++;
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            sendMessage($chat_id, "🎯 Click a movie to get it:", $keyboard, 'HTML');
        }
    }
    exit;
}

// Callback query handling
if (isset($update['callback_query'])) {
    handle_callback($update['callback_query']);
    exit;
}
