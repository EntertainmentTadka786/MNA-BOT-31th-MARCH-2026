<?php
function add_movie_request($user_id, $movie_name) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $today = date('Y-m-d');
    
    // Check daily limit
    $today_count = 0;
    foreach ($requests['requests'] as $req) {
        if ($req['user_id'] == $user_id && $req['date'] == $today) {
            $today_count++;
        }
    }
    if ($today_count >= DAILY_REQUEST_LIMIT) return false;
    
    // Check duplicate pending
    foreach ($requests['requests'] as $req) {
        if ($req['user_id'] == $user_id && strtolower($req['movie_name']) == strtolower($movie_name) && $req['status'] == 'pending') {
            return false;
        }
    }
    
    $req_id = 'req_' . time() . '_' . rand(1000, 9999);
    $requests['requests'][] = [
        'id' => $req_id,
        'user_id' => $user_id,
        'movie_name' => $movie_name,
        'date' => $today,
        'status' => 'pending'
    ];
    file_put_contents(REQUESTS_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    
    sendMessage(ADMIN_ID, "📝 New request: $movie_name from user $user_id\nID: $req_id");
    bot_log("Request added: $movie_name by $user_id");
    return true;
}

function show_pending_requests($chat_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $pending = array_filter($requests['requests'], fn($r) => $r['status'] == 'pending');
    
    if (empty($pending)) {
        sendMessage($chat_id, "✅ No pending requests.");
        return;
    }
    
    $msg = "📋 <b>Pending Requests</b> (" . count($pending) . ")\n\n";
    $keyboard = ['inline_keyboard' => []];
    $i = 1;
    foreach ($pending as $req) {
        $msg .= "$i. {$req['movie_name']} - User {$req['user_id']} ({$req['date']})\n";
        $keyboard['inline_keyboard'][] = [
            ['text' => "✅ Approve: {$req['movie_name']}", 'callback_data' => "approve_req_{$req['id']}"],
            ['text' => "❌ Reject", 'callback_data' => "reject_req_{$req['id']}"]
        ];
        $i++;
    }
    $msg .= "\nUse /bulk_approve <count> to approve latest N requests.";
    sendMessage($chat_id, $msg, $keyboard, 'HTML');
}

function show_user_requests($chat_id, $user_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $user_reqs = array_filter($requests['requests'], fn($r) => $r['user_id'] == $user_id && $r['status'] == 'pending');
    
    if (empty($user_reqs)) {
        sendMessage($chat_id, "📭 No pending requests.");
        return;
    }
    
    $msg = "📋 <b>Your Requests</b>\n\n";
    foreach ($user_reqs as $req) {
        $msg .= "• {$req['movie_name']} ({$req['date']})\n";
    }
    sendMessage($chat_id, $msg, null, 'HTML');
}

function approve_request($req_id, $admin_chat_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $index = -1;
    foreach ($requests['requests'] as $i => $req) {
        if ($req['id'] == $req_id) {
            $index = $i;
            break;
        }
    }
    if ($index == -1) return;
    
    $req = $requests['requests'][$index];
    $movie_name = $req['movie_name'];
    $user_id = $req['user_id'];
    
    // Add to main channel CSV (default)
    $result = append_movie_to_csv('main', $movie_name, time(), MAIN_CHANNEL_ID);
    
    if ($result) {
        $requests['requests'][$index]['status'] = 'approved';
        file_put_contents(REQUESTS_FILE, json_encode($requests, JSON_PRETTY_PRINT));
        
        sendMessage($user_id, "🎉 Your requested movie '$movie_name' has been added!\nUse /search $movie_name to get it.");
        sendMessage($admin_chat_id, "✅ Approved: $movie_name");
        bot_log("Request approved: $movie_name for user $user_id");
    } else {
        sendMessage($admin_chat_id, "❌ Failed to add: $movie_name");
    }
}

function reject_request($req_id, $admin_chat_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $index = -1;
    foreach ($requests['requests'] as $i => $req) {
        if ($req['id'] == $req_id) {
            $index = $i;
            break;
        }
    }
    if ($index == -1) return;
    
    $req = $requests['requests'][$index];
    $movie_name = $req['movie_name'];
    $user_id = $req['user_id'];
    
    $requests['requests'][$index]['status'] = 'rejected';
    file_put_contents(REQUESTS_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    
    sendMessage($user_id, "❌ Sorry, your request for '$movie_name' has been rejected.");
    sendMessage($admin_chat_id, "❌ Rejected: $movie_name");
    bot_log("Request rejected: $movie_name for user $user_id");
}

function bulk_approve_requests($count) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $pending = array_filter($requests['requests'], fn($r) => $r['status'] == 'pending');
    usort($pending, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    $to_approve = array_slice($pending, 0, $count);
    
    $approved = 0;
    $failed = 0;
    foreach ($to_approve as $req) {
        $result = append_movie_to_csv('main', $req['movie_name'], time(), MAIN_CHANNEL_ID);
        if ($result) {
            foreach ($requests['requests'] as $i => $r) {
                if ($r['id'] == $req['id']) {
                    $requests['requests'][$i]['status'] = 'approved';
                    sendMessage($req['user_id'], "🎉 Your requested movie '{$req['movie_name']}' has been added!");
                    $approved++;
                    break;
                }
            }
        } else {
            $failed++;
        }
    }
    
    file_put_contents(REQUESTS_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    return ['approved' => $approved, 'failed' => $failed];
}

function show_csv_data($chat_id) {
    $msg = "📁 <b>Channel CSV Data</b>\n\n";
    foreach (PUBLIC_CHANNELS as $type => $info) {
        $csv_path = CHANNELS_DIR . $info['csv'];
        if (!file_exists($csv_path)) continue;
        
        $lines = file($csv_path);
        $count = count($lines) - 1; // minus header
        $msg .= "{$info['display']}: $count movies\n";
    }
    $msg .= "\n📂 Location: " . CHANNELS_DIR;
    sendMessage($chat_id, $msg, null, 'HTML');
}
