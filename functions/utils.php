<?php
function bot_log($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $type: $message\n", FILE_APPEND);
}

function update_stats($field, $increment = 1) {
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    return json_decode(file_get_contents(STATS_FILE), true);
}

function detect_language($text) {
    $hindi = preg_match('/[\x{0900}-\x{097F}]/u', $text);
    return $hindi ? 'hindi' : 'english';
}

function send_typing_action($chat_id) {
    apiRequest('sendChatAction', ['chat_id' => $chat_id, 'action' => 'typing']);
}

function get_movie_name_from_post($post) {
    $text = '';
    if (isset($post['caption'])) {
        $text = $post['caption'];
    } elseif (isset($post['text'])) {
        $text = $post['text'];
    } elseif (isset($post['document']['file_name'])) {
        $text = $post['document']['file_name'];
    }
    
    // Clean common words
    $remove = ['download', 'watch', 'free', 'full movie', 'hd', '1080p', '720p', '480p', 'theater', 'print'];
    foreach ($remove as $word) {
        $text = str_ireplace($word, '', $text);
    }
    
    return trim(preg_replace('/\s+/', ' ', $text));
}
