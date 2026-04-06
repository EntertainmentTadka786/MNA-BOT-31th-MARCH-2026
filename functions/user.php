<?php
function update_user_data($user_id, $user_info = []) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id] = [
            'first_name' => $user_info['first_name'] ?? '',
            'username' => $user_info['username'] ?? '',
            'joined' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s'),
            'total_searches' => 0,
            'total_downloads' => 0
        ];
        update_stats('total_users', 1);
        bot_log("New user: $user_id");
    }
    $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
    return $users_data['users'][$user_id];
}

function update_user_activity($user_id, $action) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (isset($users_data['users'][$user_id])) {
        if ($action == 'search') $users_data['users'][$user_id]['total_searches']++;
        if ($action == 'download') $users_data['users'][$user_id]['total_downloads']++;
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
    }
}

function show_user_stats($chat_id, $user_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $user = $users_data['users'][$user_id] ?? null;
    if (!$user) {
        sendMessage($chat_id, "❌ No data found.");
        return;
    }
    $msg = "👤 <b>Your Stats</b>\n\n";
    $msg .= "📅 Joined: " . ($user['joined'] ?? 'N/A') . "\n";
    $msg .= "🔍 Searches: " . ($user['total_searches'] ?? 0) . "\n";
    $msg .= "📥 Downloads: " . ($user['total_downloads'] ?? 0);
    sendMessage($chat_id, $msg, null, 'HTML');
}
