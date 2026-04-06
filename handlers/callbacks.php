<?php
function handle_callback($query) {
    $chat_id = $query['message']['chat']['id'];
    $user_id = $query['from']['id'];
    $data = $query['data'];
    $is_admin = ($user_id == ADMIN_ID);
    
    send_typing_action($chat_id);
    
    // Movie selection callback
    if (strpos($data, '{') === 0) {
        $movie = json_decode($data, true);
        if ($movie && isset($movie['message_id'])) {
            deliver_movie($chat_id, $movie);
            update_user_activity($user_id, 'download');
            answerCallbackQuery($query['id'], "✅ Movie sent!");
        } else {
            answerCallbackQuery($query['id'], "❌ Error", true);
        }
        return;
    }
    
    // Help menu callbacks
    $help_commands = [
        'help_search' => "🔍 /search <movie>\nExample: /search kgf\n\nJust type movie name directly also works.",
        'help_totalupload' => "📁 /totalupload\nShows all recent movies from all channels.",
        'help_latest' => "🆕 /latest\nShows latest added movies.",
        'help_theater' => "🎭 /theater\nShows theater print movies only.",
        'help_request' => "📝 /request <movie>\nRequest a movie that's not available.",
        'help_myrequests' => "📋 /myrequests\nCheck your pending requests.",
        'help_channels' => "📢 /channels\nJoin our public channels.",
        'help_info' => "ℹ️ /info\nBot statistics and info.",
        'help_support' => "🆘 /support\nContact for help.",
        'help_mystats' => "📊 /mystats\nYour activity stats."
    ];
    
    if (isset($help_commands[$data])) {
        $back_kb = ['inline_keyboard' => [[['text' => '« Back to Help', 'callback_data' => 'help_menu']]]];
        sendMessage($chat_id, $help_commands[$data], $back_kb, 'HTML');
        answerCallbackQuery($query['id']);
        return;
    }
    
    if ($data == 'help_menu') {
        show_help_menu($chat_id);
        answerCallbackQuery($query['id']);
        return;
    }
    
    // Admin panel callbacks
    if ($data == 'admin_panel' && $is_admin) {
        $panel_kb = [
            'inline_keyboard' => [
                [['text' => '📋 Pending Requests', 'callback_data' => 'admin_pending']],
                [['text' => '📊 Statistics', 'callback_data' => 'admin_stats']],
                [['text' => '💾 Backup Now', 'callback_data' => 'admin_backup']],
                [['text' => '📢 Broadcast', 'callback_data' => 'admin_broadcast']],
                [['text' => '📁 View CSV', 'callback_data' => 'admin_csv']],
                [['text' => '📜 Recent Logs', 'callback_data' => 'admin_logs']],
                [['text' => '❌ Close', 'callback_data' => 'admin_close']]
            ]
        ];
        sendMessage($chat_id, "🔐 <b>Admin Panel</b>\nSelect an option:", $panel_kb, 'HTML');
        answerCallbackQuery($query['id']);
        return;
    }
    
    if ($data == 'admin_pending' && $is_admin) {
        show_pending_requests($chat_id);
        answerCallbackQuery($query['id']);
        return;
    }
    
    if (strpos($data, 'approve_req_') === 0 && $is_admin) {
        $req_id = str_replace('approve_req_', '', $data);
        approve_request($req_id, $chat_id);
        answerCallbackQuery($query['id'], "✅ Approved");
        return;
    }
    
    if (strpos($data, 'reject_req_') === 0 && $is_admin) {
        $req_id = str_replace('reject_req_', '', $data);
        reject_request($req_id, $chat_id);
        answerCallbackQuery($query['id'], "❌ Rejected");
        return;
    }
    
    if ($data == 'admin_stats' && $is_admin) {
        $stats = get_stats();
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        $msg = "📊 <b>Bot Statistics</b>\n\n";
        $msg .= "🎬 Movies: " . ($stats['total_movies'] ?? 0) . "\n";
        $msg .= "👥 Users: " . count($users_data['users'] ?? []) . "\n";
        $msg .= "🔍 Searches: " . ($stats['total_searches'] ?? 0) . "\n";
        $msg .= "📥 Downloads: " . ($stats['total_downloads'] ?? 0);
        sendMessage($chat_id, $msg, null, 'HTML');
        answerCallbackQuery($query['id']);
        return;
    }
    
    if ($data == 'admin_backup' && $is_admin) {
        manual_backup($chat_id);
        answerCallbackQuery($query['id'], "Backup started");
        return;
    }
    
    if ($data == 'admin_broadcast' && $is_admin) {
        sendMessage($chat_id, "📢 Send your broadcast message below:");
        // Store waiting for broadcast input (simplified - in production use session)
        answerCallbackQuery($query['id'], "Type your message");
        return;
    }
    
    if ($data == 'admin_csv' && $is_admin) {
        show_csv_data($chat_id);
        answerCallbackQuery($query['id']);
        return;
    }
    
    if ($data == 'admin_logs' && $is_admin) {
        if (file_exists(LOG_FILE)) {
            $logs = array_slice(file(LOG_FILE), -30);
            $msg = "📜 <b>Last 30 Logs</b>\n\n<code>" . htmlspecialchars(implode('', $logs)) . "</code>";
            sendMessage($chat_id, $msg, null, 'HTML');
        } else {
            sendMessage($chat_id, "No logs found.");
        }
        answerCallbackQuery($query['id']);
        return;
    }
    
    if ($data == 'admin_close' && $is_admin) {
        deleteMessage($chat_id, $query['message']['message_id']);
        answerCallbackQuery($query['id'], "Closed");
        return;
    }
    
    answerCallbackQuery($query['id'], "❌ Not available", true);
}

function show_help_menu($chat_id) {
    $text = "🤖 <b>Help Menu</b>\n\nSelect a command to see details:";
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔍 Search', 'callback_data' => 'help_search'], ['text' => '📁 Browse All', 'callback_data' => 'help_totalupload']],
            [['text' => '🆕 Latest', 'callback_data' => 'help_latest'], ['text' => '🎭 Theater', 'callback_data' => 'help_theater']],
            [['text' => '📝 Request', 'callback_data' => 'help_request'], ['text' => '📋 My Requests', 'callback_data' => 'help_myrequests']],
            [['text' => '📢 Channels', 'callback_data' => 'help_channels'], ['text' => 'ℹ️ Info', 'callback_data' => 'help_info']],
            [['text' => '🆘 Support', 'callback_data' => 'help_support'], ['text' => '📊 My Stats', 'callback_data' => 'help_mystats']]
        ]
    ];
    if ($chat_id == ADMIN_ID) {
        $keyboard['inline_keyboard'][] = [['text' => '🔐 Admin Panel', 'callback_data' => 'admin_panel']];
    }
    sendMessage($chat_id, $text, $keyboard, 'HTML');
}

function get_main_keyboard($chat_id) {
    $is_admin = ($chat_id == ADMIN_ID);
    $keyboard = ['inline_keyboard' => []];
    
    $keyboard['inline_keyboard'][] = [
        ['text' => '🍿 Main', 'url' => 'https://t.me/EntertainmentTadka786'],
        ['text' => '📥 Request', 'url' => 'https://t.me/EntertainmentTadka7860']
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => '🎭 Theater', 'url' => 'https://t.me/threater_print_movies'],
        ['text' => '📂 Backup', 'url' => 'https://t.me/ETBackup']
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => '📺 Serial', 'url' => 'https://t.me/Entertainment_Tadka_Serial_786']
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔍 Search', 'switch_inline_query_current_chat' => ''],
        ['text' => '❓ Help', 'callback_data' => 'help_menu']
    ];
    
    if ($is_admin) {
        $keyboard['inline_keyboard'][] = [['text' => '🔐 Admin Panel', 'callback_data' => 'admin_panel']];
    }
    
    return $keyboard;
}
