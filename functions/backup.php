<?php
function manual_backup($chat_id) {
    if ($chat_id != ADMIN_ID) {
        sendMessage($chat_id, "❌ Admin only.");
        return;
    }
    
    sendMessage($chat_id, "🔄 Starting manual backup...");
    
    $backup_time = date('Y-m-d_H-i-s');
    $backup_folder = BACKUP_DIR . $backup_time . '/';
    mkdir($backup_folder, 0755, true);
    
    // Copy all CSV files
    foreach (glob(CHANNELS_DIR . '*.csv') as $file) {
        copy($file, $backup_folder . basename($file));
    }
    
    // Copy JSON files
    $json_files = [INDEXED_FILE, REQUESTS_FILE, USERS_FILE, STATS_FILE];
    foreach ($json_files as $file) {
        if (file_exists($file)) {
            copy($file, $backup_folder . basename($file));
        }
    }
    
    sendMessage($chat_id, "✅ Backup completed!\n📁 Location: " . $backup_folder);
    bot_log("Manual backup by $chat_id");
}

function backup_status($chat_id) {
    if ($chat_id != ADMIN_ID) return;
    
    $backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    $count = count($backups);
    $latest = !empty($backups) ? basename(end($backups)) : 'None';
    
    $msg = "💾 <b>Backup Status</b>\n\n";
    $msg .= "📁 Total backups: $count\n";
    $msg .= "🕒 Latest: $latest\n";
    $msg .= "📂 Location: " . BACKUP_DIR;
    sendMessage($chat_id, $msg, null, 'HTML');
}
