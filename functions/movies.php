<?php
function append_movie_to_csv($channel_type, $movie_name, $message_id, $channel_id) {
    $csv_path = get_channel_csv_path($channel_type);
    if (!$csv_path) return false;
    
    $movie_name = trim($movie_name);
    if (empty($movie_name)) return false;
    
    $handle = fopen($csv_path, 'a');
    fputcsv($handle, [$movie_name, $message_id, $channel_id]);
    fclose($handle);
    
    update_stats('total_movies', 1);
    bot_log("Movie added to $channel_type: $movie_name (ID: $message_id)");
    return true;
}

function auto_index_post($post, $channel_id, $channel_type) {
    $message_id = $post['message_id'];
    
    // Check if already indexed
    $indexed = json_decode(file_get_contents(INDEXED_FILE), true);
    if (isset($indexed[$channel_id]) && in_array($message_id, $indexed[$channel_id])) {
        return false;
    }
    
    $movie_name = get_movie_name_from_post($post);
    if (empty($movie_name)) return false;
    
    // Append to channel CSV
    $result = append_movie_to_csv($channel_type, $movie_name, $message_id, $channel_id);
    
    if ($result) {
        // Mark as indexed
        if (!isset($indexed[$channel_id])) $indexed[$channel_id] = [];
        $indexed[$channel_id][] = $message_id;
        file_put_contents(INDEXED_FILE, json_encode($indexed, JSON_PRETTY_PRINT));
    }
    
    return $result;
}

function search_movies($query) {
    $results = [];
    $query_lower = strtolower(trim($query));
    
    foreach (PUBLIC_CHANNELS as $type => $info) {
        $csv_path = CHANNELS_DIR . $info['csv'];
        if (!file_exists($csv_path)) continue;
        
        $handle = fopen($csv_path, 'r');
        fgetcsv($handle); // skip header
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;
            $movie_name = $row[0];
            $message_id = $row[1];
            $ch_id = $row[2] ?? $info['id'];
            
            if (stripos($movie_name, $query_lower) !== false) {
                $results[] = [
                    'movie_name' => $movie_name,
                    'message_id' => $message_id,
                    'channel_id' => $ch_id,
                    'channel_type' => $type,
                    'channel_username' => $info['username']
                ];
            }
        }
        fclose($handle);
    }
    
    return $results;
}

function get_movies_from_channel($channel_type, $limit = 50) {
    $csv_path = get_channel_csv_path($channel_type);
    if (!$csv_path || !file_exists($csv_path)) return [];
    
    $movies = [];
    $handle = fopen($csv_path, 'r');
    fgetcsv($handle); // skip header
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 2) {
            $movies[] = [
                'movie_name' => $row[0],
                'message_id' => $row[1],
                'channel_id' => $row[2] ?? '',
                'channel_type' => $channel_type
            ];
        }
    }
    fclose($handle);
    
    return array_slice(array_reverse($movies), 0, $limit);
}

function get_all_movies($limit_per_channel = 20) {
    $all_movies = [];
    foreach (PUBLIC_CHANNELS as $type => $info) {
        $movies = get_movies_from_channel($type, $limit_per_channel);
        $all_movies = array_merge($all_movies, $movies);
    }
    return $all_movies;
}

function deliver_movie($chat_id, $movie) {
    $source_channel = $movie['channel_id'];
    $channel_type = $movie['channel_type'];
    $message_id = $movie['message_id'];
    $is_public = is_public_channel($channel_type);
    
    if ($is_public) {
        // Public channel - forward (header ON)
        $result = json_decode(forwardMessage($chat_id, $source_channel, $message_id), true);
        if ($result && isset($result['ok']) && $result['ok']) {
            update_stats('total_downloads', 1);
            return true;
        }
        // Fallback to copy
        $result = json_decode(copyMessage($chat_id, $source_channel, $message_id), true);
        if ($result && isset($result['ok']) && $result['ok']) {
            update_stats('total_downloads', 1);
            return true;
        }
    } else {
        // Private channel - copy only (no header)
        $result = json_decode(copyMessage($chat_id, $source_channel, $message_id), true);
        if ($result && isset($result['ok']) && $result['ok']) {
            update_stats('total_downloads', 1);
            return true;
        }
    }
    
    // Fallback text
    $text = "🎬 <b>" . htmlspecialchars($movie['movie_name']) . "</b>\n";
    if ($is_public && isset($movie['channel_username'])) {
        $text .= "\n📢 Join: " . $movie['channel_username'];
    } else {
        $text .= "\n⚠️ Content temporarily unavailable.";
    }
    sendMessage($chat_id, $text, null, 'HTML');
    update_stats('total_downloads', 1);
    return false;
}
