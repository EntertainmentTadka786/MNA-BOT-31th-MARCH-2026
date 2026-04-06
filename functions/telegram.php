<?php
function apiRequest($method, $params = [], $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    } else {
        $options = ['http' => ['method' => 'POST', 'content' => http_build_query($params), 'header' => "Content-Type: application/x-www-form-urlencoded\r\n"]];
        $context = stream_context_create($options);
        return @file_get_contents($url, false, $context);
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'disable_web_page_preview' => true];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    $result = apiRequest('sendMessage', $data);
    return json_decode($result, true);
}

function editMessage($chat_id, $message_id, $new_text, $reply_markup = null) {
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $new_text, 'disable_web_page_preview' => true];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    apiRequest('editMessageText', $data);
}

function deleteMessage($chat_id, $message_id) {
    apiRequest('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
}

function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $data = ['callback_query_id' => $callback_query_id, 'show_alert' => $show_alert];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', ['chat_id' => $chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('forwardMessage', ['chat_id' => $chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id]);
}
