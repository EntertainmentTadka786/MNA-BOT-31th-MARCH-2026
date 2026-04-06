<?php
function handle_command($chat_id, $user_id, $command, $params = []) {
    $is_admin = ($user_id == ADMIN_ID);
    
    switch ($command) {
        case '/start':
            send_typing_action($chat_id);
            $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n";
            $welcome .= "🔍 Just type a movie name to search.\n\n";
            $welcome .= "📝 Examples:\n";
            $welcome .= "• Mandala Murders 2025\n";
            $welcome .= "• Zebra 2024\n";
            $welcome .= "• Now You See Me\n";
            $welcome .= "• Squid Game\n";
            $welcome .= "• Show Time (2024)\n";
            $welcome .= "• Taskaree S01\n\n";
            $welcome .= "💡 Use /help for all commands.";
            
            $keyboard = get_main_keyboard($chat_id);
            sendMessage($chat_id, $welcome, $keyboard, 'HTML');
            update_user_activity($user_id, 'search');
            break;
            
        case '/help':
            send_typing_action($chat_id);
            show_help_menu($chat_id);
            break;
            
        case '/search':
        case '/s':
            send_typing_action($chat_id);
            $query = implode(' ', $params);
            if (empty($query)) {
                sendMessage($chat_id, "❌ Usage: /search <movie name>");
                return;
            }
            $results = search_movies($query);
            if (empty($results)) {
                sendMessage($chat_id, "😔 No movies found for '$query'.\n\n📝 Use /request to request this movie.");
                update_stats('failed_searches', 1);
                return;
            }
            
            update_stats('successful_searches', 1);
            update_user_activity($user_id, 'search');
            
            $msg = "🔍 Found " . count($results) . " results for '$query':\n\n";
            $i = 1;
            $keyboard = ['inline_keyboard' => []];
            foreach (array_slice($results, 0, 10) as $movie) {
                $display = get_channel_display_name($movie['channel_type'], false);
                $msg .= "$i. $display " . htmlspecialchars($movie['movie_name']) . "\n";
                $keyboard['inline_keyboard'][] = [['text' => "$i. " . htmlspecialchars($movie['movie_name']), 'callback_data' => json_encode($movie)]];
                $i++;
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            sendMessage($chat_id, "🎯 Click a movie to get it:", $keyboard, 'HTML');
            break;
            
        case '/totalupload':
            send_typing_action($chat_id);
            $movies = get_all_movies(30);
            if (empty($movies)) {
                sendMessage($chat_id, "📭 No movies found.");
                return;
            }
            $msg = "🎬 <b>Recent Movies</b>\n\n";
            $i = 1;
            foreach ($movies as $movie) {
                $display = get_channel_display_name($movie['channel_type'], false);
                $msg .= "$i. $display " . htmlspecialchars($movie['movie_name']) . "\n";
                $i++;
                if ($i > 20) break;
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/latest':
            send_typing_action($chat_id);
            $movies = [];
            foreach (PUBLIC_CHANNELS as $type => $info) {
                $channel_movies = get_movies_from_channel($type, 5);
                $movies = array_merge($movies, $channel_movies);
            }
            usort($movies, function($a, $b) {
                return strcmp($b['movie_name'], $a['movie_name']);
            });
            $msg = "🆕 <b>Latest Movies</b>\n\n";
            foreach (array_slice($movies, 0, 15) as $movie) {
                $msg .= "• " . htmlspecialchars($movie['movie_name']) . "\n";
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/theater':
            send_typing_action($chat_id);
            $movies = get_movies_from_channel('theater', 20);
            if (empty($movies)) {
                sendMessage($chat_id, "🎭 No theater prints found.");
                return;
            }
            $msg = "🎭 <b>Theater Prints</b>\n\n";
            foreach ($movies as $movie) {
                $msg .= "• " . htmlspecialchars($movie['movie_name']) . "\n";
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/request':
            send_typing_action($chat_id);
            $movie_name = implode(' ', $params);
            if (empty($movie_name)) {
                sendMessage($chat_id, "❌ Usage: /request <movie name>");
                return;
            }
            if (add_movie_request($user_id, $movie_name)) {
                sendMessage($chat_id, "✅ Request added! Admin will review.");
            } else {
                sendMessage($chat_id, "❌ You've reached daily limit (" . DAILY_REQUEST_LIMIT . " requests).");
            }
            break;
            
        case '/myrequests':
            send_typing_action($chat_id);
            show_user_requests($chat_id, $user_id);
            break;
            
        case '/pending_request':
            if (!$is_admin) {
                sendMessage($chat_id, "❌ Admin only.");
                return;
            }
            send_typing_action($chat_id);
            show_pending_requests($chat_id);
            break;
            
        case '/bulk_approve':
            if (!$is_admin) {
                sendMessage($chat_id, "❌ Admin only.");
                return;
            }
            $count = (int)($params[0] ?? 0);
            if ($count <= 0) {
                sendMessage($chat_id, "❌ Usage: /bulk_approve <count>");
                return;
            }
            send_typing_action($chat_id);
            $result = bulk_approve_requests($count);
            sendMessage($chat_id, "✅ Approved: {$result['approved']}\n❌ Failed: {$result['failed']}");
            break;
            
        case '/mystats':
            send_typing_action($chat_id);
            show_user_stats($chat_id, $user_id);
            break;
            
        case '/channels':
            send_typing_action($chat_id);
            $msg = "🔥 <b>Join Our Channels</b>\n\n";
            foreach (PUBLIC_CHANNELS as $info) {
                $msg .= "{$info['display']}: {$info['username']}\n";
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/mainchannel':
            send_typing_action($chat_id);
            sendMessage($chat_id, "🍿 Main Channel: " . MAIN_CHANNEL_USERNAME);
            break;
            
        case '/info':
            send_typing_action($chat_id);
            $stats = get_stats();
            $msg = "🤖 <b>Bot Info</b>\n\n";
            $msg .= "📊 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
            $msg .= "👥 Total Users: " . ($stats['total_users'] ?? 0) . "\n";
            $msg .= "🔍 Searches: " . ($stats['total_searches'] ?? 0) . "\n";
            $msg .= "📥 Downloads: " . ($stats['total_downloads'] ?? 0);
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/support':
            send_typing_action($chat_id);
            $msg = "🆘 <b>Support</b>\n\n";
            $msg .= "📢 Request Channel: " . REQUEST_CHANNEL_USERNAME . "\n";
            $msg .= "👨‍💻 Admin: @EntertainmentTadka0786";
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/report':
            send_typing_action($chat_id);
            $bug = implode(' ', $params);
            if (empty($bug)) {
                sendMessage($chat_id, "❌ Usage: /report <bug description>");
                return;
            }
            sendMessage(ADMIN_ID, "🐛 Bug report from $user_id:\n$bug");
            sendMessage($chat_id, "✅ Bug reported! Thanks.");
            break;
            
        case '/feedback':
            send_typing_action($chat_id);
            $fb = implode(' ', $params);
            if (empty($fb)) {
                sendMessage($chat_id, "❌ Usage: /feedback <message>");
                return;
            }
            sendMessage(ADMIN_ID, "💬 Feedback from $user_id:\n$fb");
            sendMessage($chat_id, "✅ Thanks for your feedback!");
            break;
            
        case '/stats':
            if (!$is_admin) {
                sendMessage($chat_id, "❌ Admin only.");
                return;
            }
            send_typing_action($chat_id);
            $stats = get_stats();
            $users_data = json_decode(file_get_contents(USERS_FILE), true);
            $msg = "📊 <b>Bot Statistics</b>\n\n";
            $msg .= "🎬 Movies: " . ($stats['total_movies'] ?? 0) . "\n";
            $msg .= "👥 Users: " . count($users_data['users'] ?? []) . "\n";
            $msg .= "🔍 Searches: " . ($stats['total_searches'] ?? 0) . "\n";
            $msg .= "📥 Downloads: " . ($stats['total_downloads'] ?? 0) . "\n";
            $msg .= "✅ Successful: " . ($stats['successful_searches'] ?? 0) . "\n";
            $msg .= "❌ Failed: " . ($stats['failed_searches'] ?? 0);
            sendMessage($chat_id, $msg, null, 'HTML');
            break;
            
        case '/csv':
            if (!$is_admin) {
                sendMessage($chat_id, "❌ Admin only.");
                return;
            }
            send_typing_action($chat_id);
            show_csv_data($chat_id
