<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dating_website');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/uploads';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

class DatingWebsite {
    private $db;
    private $currentUser;
    
    public function __construct() {
        try {
            $this->db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
                DB_USER,
                DB_PASS
            );
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $this->initDatabase();
            
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
        
        $this->currentUser = isset($_SESSION['username']) ? $_SESSION['username'] : null;
        $this->updateUserStatus();
    }
    
    private function initDatabase() {
        $tables = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(16) UNIQUE NOT NULL,
                session_id VARCHAR(255) NOT NULL,
                last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_online BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender VARCHAR(16) NOT NULL,
                recipient VARCHAR(16) NOT NULL,
                content TEXT NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS media (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender VARCHAR(16) NOT NULL,
                recipient VARCHAR(16) NOT NULL,
                type ENUM('image', 'video') NOT NULL,
                filepath VARCHAR(255) NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS public_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender VARCHAR(16) NOT NULL,
                content TEXT NOT NULL,
                encryption_key VARCHAR(255),
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS video_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender VARCHAR(16) NOT NULL,
                recipient VARCHAR(16) NOT NULL,
                room_id VARCHAR(255) NOT NULL,
                status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS shared_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender VARCHAR(16) NOT NULL,
                recipient VARCHAR(16) NOT NULL,
                encryption_key VARCHAR(255) NOT NULL,
                shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS site_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                total_visits INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ";
        
        foreach (explode(';', $tables) as $table) {
            if (trim($table)) {
                $this->db->exec($table);
            }
        }
    }
private function getEncryptionKeys($username) {
    $stmt = $this->db->prepare("SELECT key_name, encryption_key FROM encryption_keys WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
    private function updateUserStatus() {
        if ($this->currentUser) {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET last_active = CURRENT_TIMESTAMP, 
                    is_online = TRUE 
                WHERE username = ?
            ");
            $stmt->execute([$this->currentUser]);
        }

        // Set users inactive after 5 minutes of no activity
        $stmt = $this->db->prepare("
            UPDATE users 
            SET is_online = FALSE 
            WHERE last_active < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute();
    }

    public function handleCommand($input) {
        $parts = explode(' ', trim($input));
        $command = strtolower($parts[0]);

        // Remove any script tags from input
        $input = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $input);

        switch($command) {
            case '/username':
                if(isset($parts[1])) {
                    return $this->setUsername($parts[1]);
                }
                break;

            case '/msg':
                if(count($parts) >= 3) {
                    $target = $parts[1];
                    $message = implode(' ', array_slice($parts, 2));
                    return $this->sendMessage($target, $message);
                }
                break;

            case '/img':
                if(isset($parts[1])) {
                    return $this->handleImageUpload($parts[1]);
                }
                break;

            case '/live':
                if(isset($parts[1])) {
                    return $this->initiateVideoStream($parts[1]);
                }
                break;

            case '/share':
                if(count($parts) >= 3) {
                    $key = $parts[1];
                    $target = $parts[2];
                    return $this->shareKey($key, $target);
                }
                break;

            default:
                return "Invalid command. Use /help for command list";
        }
    }

    private function setUsername($newUsername) {
        if(preg_match('/^[a-zA-Z0-9_]{3,16}$/', $newUsername)) {
            $stmt = $this->db->prepare("SELECT username FROM users WHERE username = ?");
            $stmt->execute([$newUsername]);
            
            if($stmt->rowCount() == 0) {
                $_SESSION['username'] = $newUsername;
                $this->currentUser = $newUsername;
                
                $stmt = $this->db->prepare("INSERT INTO users (username, session_id) VALUES (?, ?)");
                $stmt->execute([$newUsername, session_id()]);
                
                return ["status" => "success", "message" => "Username set to: " . $newUsername];
            }
            return ["status" => "error", "message" => "Username already taken"];
        }
        return ["status" => "error", "message" => "Invalid username format"];
    }

    private function sendMessage($target, $message) {
        // Convert URLs to hyperlinks
        $message = preg_replace(
            '/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/',
            '<a href="$0" target="_blank">$0</a>',
            $message
        );

        if($target === 'all') {
              if(strpos($message, 'key') !== false) {
        list($msg, $key) = explode('key', $message);
        $keyName = trim($key);
        $msg = trim($msg);
        
        $encryptedMsg = openssl_encrypt(
            $msg,
            'AES-256-CBC',
            $keyName,
            0,
            str_repeat("0", 16)
        );
        
        // Store the encryption key
        $stmt = $this->db->prepare("INSERT INTO encryption_keys (username, key_name, encryption_key) VALUES (?, ?, ?)");
        $stmt->execute([$this->currentUser, $keyName, $keyName]);
        
        return $this->broadcastMessage($encryptedMsg, $keyName);
    }
            return $this->broadcastMessage($message);
        }
        
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND is_online = TRUE");
        $stmt->execute([$target]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $this->db->prepare("INSERT INTO messages (sender, recipient, content) VALUES (?, ?, ?)");
            $stmt->execute([$this->currentUser, $target, $message]);
            return ["status" => "success", "message" => "Message sent to " . $target];
        }
        return ["status" => "error", "message" => "User not found or offline"];
    }

    private function handleImageUpload($target) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND is_online = TRUE");
        $stmt->execute([$target]);
        
        if($stmt->rowCount() == 0) {
            return ["status" => "error", "message" => "User not found or offline"];
        }

        if(isset($_FILES['image'])) {
            $file = $_FILES['image'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            
            if(in_array($file['type'], $allowedTypes)) {
                $filename = uniqid() . '_' . basename($file['name']);
                $uploadPath = 'uploads/' . $filename;
                
                if(move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $stmt = $this->db->prepare("INSERT INTO media (sender, recipient, type, filepath) VALUES (?, ?, 'image', ?)");
                    $stmt->execute([$this->currentUser, $target, $uploadPath]);
                    return ["status" => "success", "message" => "Image sent to " . $target];
                }
            }
            return ["status" => "error", "message" => "Invalid file type"];
        }
        return ["status" => "error", "message" => "No image uploaded"];
    }

    private function initiateVideoStream($target) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND is_online = TRUE");
        $stmt->execute([$target]);
        
        if($stmt->rowCount() == 0) {
            return ["status" => "error", "message" => "User not found or offline"];
        }

        $roomId = uniqid('room_');
        
        $stmt = $this->db->prepare("INSERT INTO video_requests (sender, recipient, room_id) VALUES (?, ?, ?)");
        $stmt->execute([$this->currentUser, $target, $roomId]);
        
        return [
            "status" => "success",
            "type" => "video_request",
            "room" => $roomId,
            "target" => $target
        ];
    }

    private function shareKey($key, $target) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND is_online = TRUE");
        $stmt->execute([$target]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $this->db->prepare("INSERT INTO shared_keys (sender, recipient, encryption_key) VALUES (?, ?, ?)");
            $stmt->execute([$this->currentUser, $target, $key]);
            return ["status" => "success", "message" => "Encryption key shared with " . $target];
        }
        return ["status" => "error", "message" => "User not found or offline"];
    }

    private function broadcastMessage($message, $key = null) {
        $stmt = $this->db->prepare("INSERT INTO public_messages (sender, content, encryption_key) VALUES (?, ?, ?)");
        $stmt->execute([$this->currentUser, $message, $key]);
        return ["status" => "success", "message" => "Message broadcasted" . ($key ? " with key: " . $key : "")];
    }

    public function getMessages() {
        $messages = [];
        
        // Get public messages (newest first)
        $stmt = $this->db->query("SELECT * FROM public_messages ORDER BY sent_at DESC LIMIT 50");
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = [
                'type' => 'public',
                'id' => $row['id'],
                'sender' => $row['sender'],
                'content' => $row['content'],
                'key' => $row['encryption_key'],
                'time' => $row['sent_at']
            ];
        }
        
        // Get private messages for current user
        if($this->currentUser) {
            $stmt = $this->db->prepare("
                SELECT * FROM messages 
                WHERE recipient = ? OR sender = ?
                ORDER BY sent_at DESC LIMIT 50
            ");
            $stmt->execute([$this->currentUser, $this->currentUser]);
            
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $messages[] = [
                    'type' => 'private',
                    'id' => $row['id'],
                    'sender' => $row['sender'],
                    'recipient' => $row['recipient'],
                    'content' => $row['content'],
                    'time' => $row['sent_at']
                ];
            }
        }
        
        return array_reverse($messages); // Reverse to show newest at bottom
    }

    public function getSiteStats() {
        $stmt = $this->db->query("SELECT COUNT(*) as online_users FROM users WHERE is_online = TRUE");
        $onlineUsers = $stmt->fetch(PDO::FETCH_ASSOC)['online_users'];

        $stmt = $this->db->query("SELECT total_visits FROM site_stats WHERE id = 1");
        $totalVisits = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$totalVisits) {
            $this->db->exec("INSERT INTO site_stats (total_visits) VALUES (1)");
            $totalVisits = 1;
        } else {
            $totalVisits = $totalVisits['total_visits'];
        }

        return [
            'online_users' => $onlineUsers,
            'total_visits' => $totalVisits
        ];
    }

    public function incrementVisits() {
        $this->db->exec("UPDATE site_stats SET total_visits = total_visits + 1 WHERE id = 1");
    }
}

// Handle AJAX requests
if(isset($_POST['action'])) {
    $dating = new DatingWebsite();
    $response = [];
    
    switch($_POST['action']) {
        case 'command':
            if(isset($_POST['input'])) {
                $response = $dating->handleCommand($_POST['input']);
            }
            break;
            
        case 'getMessages':
            $response = ['messages' => $dating->getMessages()];
            break;
            
        case 'upload':
            if(isset($_POST['target']) && isset($_FILES['image'])) {
                $response = $dating->handleImageUpload($_POST['target']);
            }
            break;

        case 'getStats':
            $response = $dating->getSiteStats();
            break;
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Increment visit counter on page load
$dating = new DatingWebsite();
$dating->incrementVisits();
$stats = $dating->getSiteStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anonymous Dating</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a1a1a;
            --accent-color: #c9a55c;
            --text-light: #f8f9fa;
			}
        
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #000;
            color: var(--text-light);
        }
        
        .brand-heading {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--accent-color);
        }
        
        .stats-bar {
            background: rgba(201, 165, 92, 0.1);
            border-bottom: 1px solid var(--accent-color);
            padding: 10px 0;
            margin-bottom: 30px;
        }
        
        .chat-container {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--accent-color);
            border-radius: 15px;
            height: 70vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column-reverse;
        }
        
        .message {
            background: rgba(201, 165, 92, 0.1);
            border-left: 3px solid var(--accent-color);
            margin: 10px 0;
            padding: 15px;
            position: relative;
        }
        
        .username {
            color: var(--accent-color);
            font-weight: 600;
        }
        
        .commands-list {
            background: rgba(26, 26, 26, 0.95);
            border-left: 3px solid var(--accent-color);
            padding: 20px;
        }
        
        .command {
            color: var(--accent-color);
            font-family: 'Courier New', monospace;
        }
        
        .reply-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .message:hover .reply-btn {
            opacity: 1;
        }
        
        .chat-tabs {
            margin-top: 20px;
        }
        
        .chat-tab {
            background: rgba(201, 165, 92, 0.1);
            border: 1px solid var(--accent-color);
            padding: 8px 15px;
            margin-right: 5px;
            cursor: pointer;
        }
        
        .chat-tab.active {
            background: var(--accent-color);
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12 text-center">
                <h1 class="brand-heading display-1 mb-4">ENIGMA</h1>
                <p class="lead">Where Mystery Meets Connection</p>
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="container">
                <div class="row text-center">
                    <div class="col-md-6">
                        <strong>Online Users:</strong> <span id="onlineUsers"><?php echo $stats['online_users']; ?></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Total Visits:</strong> <span id="totalVisits"><?php echo $stats['total_visits']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <div id="chatTabs" class="chat-tabs"></div>
                    <div class="chat-container p-4 mb-4" id="chatMessages"></div>
                    
                    <div class="input-group">
                        <input type="text" class="form-control bg-dark text-light border-secondary" 
                               id="messageInput" placeholder="Type your command...">
                        <button class="btn btn-outline-light" id="sendButton">Send</button>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="commands-list">
                        <h4 class="brand-heading mb-4">Commands</h4>
                        <ul class="list-unstyled">
                            <li class="mb-3">
                                <code class="command">/username name</code>
                                <p class="small mb-0">Set your anonymous identity (3-16 characters, alphanumeric)</p>
                            </li>
                            <li class="mb-3">
                                <code class="command">/msg username message</code>
                                <p class="small mb-0">Send private message to an online user</p>
                            </li>
                            <li class="mb-3">
                                <code class="command">/msg all message</code>
                                <p class="small mb-0">Send public message (URLs automatically become clickable)</p>
                            </li>
                            <li class="mb-3">
                                <code class="command">/msg all message key secretkey</code>
                                <p class="small mb-0">Send encrypted public message</p>
                            </li>
                            <li class="mb-3">
                                <code class="command">/share key username</code>
                                <p class="small mb-0">Share encryption key with another user</p>
                            </li>
                            <li class="mb-3">
                                <code class="command">/img username</code>
                                <p class="small mb-0">Share an image with an online user</p>
                            </li>
                            <li class="mb-3">
                                <code class="command">/live username</code>
                                <p class="small mb-0">Request live video chat with an online user</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Upload Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Upload Image</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadForm">
                        <input type="file" class="form-control bg-dark text-light" name="image" accept="image/*">
                        <input type="hidden" name="target" id="uploadTarget">
                    </form>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="uploadButton">Upload</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Video Chat Modal -->
    <div class="modal fade" id="videoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Video Chat</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <video id="localVideo" autoplay playsinline class="w-100" style="transform: scaleX(-1);"></video>
                            <p class="text-center mt-2">You</p>
                        </div>
                        <div class="col-md-6">
                            <video id="remoteVideo" autoplay playsinline class="w-100"></video>
                            <p class="text-center mt-2">Partner</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-danger" id="endCallButton">End Call</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap and other dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
            const chatMessages = document.getElementById('chatMessages');
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
            const videoModal = new bootstrap.Modal(document.getElementById('videoModal'));
            const chatTabs = document.getElementById('chatTabs');
            let currentTarget = null;
            let activeChatTab = 'main';
            let privateChats = new Set();
let currentUser = null; // Will be set when username is chosen
           // Enhanced message sending to handle private chat creation
// Modify send message to support key-based encryption
document.addEventListener('DOMContentLoaded', function() {
    // Encryption and Decryption Utilities
    const CryptoUtils = {
        generateKey() {
            return CryptoJS.lib.WordArray.random(16).toString();
        },
        encrypt(message, key) {
            return CryptoJS.AES.encrypt(message, key).toString();
        },
        decrypt(encryptedMessage, key) {
            try {
                return CryptoJS.AES.decrypt(encryptedMessage, key).toString(CryptoJS.enc.Utf8);
            } catch (error) {
                console.error('Decryption failed');
                return null;
            }
        }
    };

    // Key Management
    const KeyManager = {
        sharedKeys: new Map(),
        storeKey(username, key) {
            this.sharedKeys.set(username, key);
        },
        getKey(username) {
            return this.sharedKeys.get(username);
        },
        hasKey(username) {
            return this.sharedKeys.has(username);
        }
    };

    // Chat State Management
    const ChatState = {
        currentUser: null,
        activeChatTab: 'main',
        privateChats: new Set(),
        
        setUsername(username) {
            this.currentUser = username;
        },
        addPrivateChat(username) {
            if (username !== this.currentUser) {
                this.privateChats.add(username);
                updateChatTabs();
            }
        },
        switchChat(tab) {
            this.activeChatTab = tab;
            updateChatTabs();
            pollMessages();
        },
        closePrivateChat(username) {
            this.privateChats.delete(username);
            if (this.activeChatTab === username) {
                this.activeChatTab = 'main';
            }
            updateChatTabs();
        }
    };

    // DOM Elements
    const chatMessages = document.getElementById('chatMessages');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');
    const chatTabs = document.getElementById('chatTabs');

    // Message Display and Handling
    function displayMessage(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'message';
        messageDiv.dataset.messageId = message.id;
        
        let content = '';
        let decryptedContent = null;

        // Determine message type
        if (message.type === 'private') {
            const otherUser = (message.sender === ChatState.currentUser) 
                ? message.recipient 
                : message.sender;
            content = `<span class="username">${message.sender} → ${message.recipient}</span>`;
            ChatState.addPrivateChat(otherUser);
        } else if (message.type === 'public') {
            content = `<span class="username">${message.sender} → all</span>`;
        } else {
            content = `<span class="username">System</span>`;
        }

        // Decryption handling
        if (message.key) {
            // Try automatic decryption
            const decryptionKey = KeyManager.getKey(message.key);
            if (decryptionKey) {
                decryptedContent = CryptoUtils.decrypt(message.content, decryptionKey);
            }

            if (decryptedContent) {
                content += `<p class="mb-0">${decryptedContent} <small class="text-success">(Auto-Decrypted)</small></p>`;
            } else {
                // Add decrypt button for encrypted messages
                messageDiv.dataset.encryptedContent = message.content;
                messageDiv.dataset.encryptionKey = message.key;
                
                const decryptButton = `
                    <button class="btn btn-sm btn-outline-warning decrypt-btn" 
                            onclick="promptForDecryptionKey('${message.id}')">
                        🔒 Decrypt
                    </button>
                `;
                content += decryptButton;
                content += `<p class="mb-0 text-muted">[Encrypted Message]</p>`;
            }
        } else {
            content += `<p class="mb-0">${message.content}</p>`;
        }

        // Reply and interaction buttons
        if (message.type !== 'system' && message.sender !== ChatState.currentUser) {
            const replyButton = `
                <button class="btn btn-sm btn-outline-light reply-btn" 
                        onclick="handleReply('${message.sender}')">Reply</button>
            `;
            content += replyButton;
        }

        // Timestamp
        if (message.time) {
            content += `<small class="text-muted">${new Date(message.time).toLocaleString()}</small>`;
        }

        messageDiv.innerHTML = content;
        chatMessages.appendChild(messageDiv);
    }

    // Decryption Key Prompt
    window.promptForDecryptionKey = function(messageId) {
        const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageElement) return;

        const encryptedContent = messageElement.dataset.encryptedContent;
        const encryptionKey = messageElement.dataset.encryptionKey;

        const key = prompt('Enter decryption key:', encryptionKey || '');
        if (key) {
            const decryptedContent = CryptoUtils.decrypt(encryptedContent, key);
            
            if (decryptedContent) {
                // Store the key for future use
                KeyManager.storeKey(key, key);
                
                messageElement.innerHTML = `
                    <span class="username">${messageElement.querySelector('.username').textContent}</span>
                    <p class="mb-0">${decryptedContent} <small class="text-success">(Decrypted)</small></p>
                    <small class="text-muted">${new Date().toLocaleString()}</small>
                `;
            } else {
                alert('Decryption failed. Invalid key.');
            }
        }
    };

    // Reply Handling
    window.handleReply = function(username) {
        if (username === ChatState.currentUser) return;
        
        // Create private chat tab
        ChatState.addPrivateChat(username);
        
        // Switch to private chat
        ChatState.switchChat(username);
        
        // Prefill message input
        messageInput.value = `/msg ${username} `;
        messageInput.focus();
    };

    // Chat Tabs Management
    function updateChatTabs() {
        chatTabs.innerHTML = '';
        
        // Main chat tab
        const mainTab = createTabElement('main', 'Main Chat');
        chatTabs.appendChild(mainTab);
        
        // Private chat tabs
        ChatState.privateChats.forEach(username => {
            const tabElement = createTabElement(username, username);
            chatTabs.appendChild(tabElement);
        });
    }

    function createTabElement(tabId, label) {
        const tab = document.createElement('div');
        tab.className = `chat-tab ${ChatState.activeChatTab === tabId ? 'active' : ''}`;
        
        const tabContent = document.createElement('span');
        tabContent.textContent = label;
        tabContent.onclick = () => ChatState.switchChat(tabId);
        tab.appendChild(tabContent);
        
        // Close button for private chats
        if (tabId !== 'main') {
            const closeButton = document.createElement('button');
            closeButton.className = 'close-tab';
            closeButton.innerHTML = '×';
            closeButton.onclick = (e) => {
                e.stopPropagation();
                ChatState.closePrivateChat(tabId);
            };
            tab.appendChild(closeButton);
        }
        
        return tab;
    }

    // Message Sending
    async function sendMessage() {
        const input = messageInput.value.trim();
        if (!input) return;
        
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=command&input=${encodeURIComponent(input)}`
            });
            
            const result = await response.json();
            
            if (result.status === 'success') {
                messageInput.value = '';
            }
            
            displayMessage({
                type: 'system',
                content: result.message || result.error,
                time: new Date().toISOString()
            });
            
        } catch (error) {
            console.error('Error:', error);
            displayMessage({
                type: 'error',
                content: 'An error occurred while sending the message.',
                time: new Date().toISOString()
            });
        }
    }

    // Message Polling
    async function pollMessages() {
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=getMessages'
            });
            
            const result = await response.json();
            chatMessages.innerHTML = '';
            
            result.messages.forEach(msg => {
                if (ChatState.activeChatTab === 'main') {
                    if (msg.type === 'public') {
                        displayMessage(msg);
                    }
                } else {
                    if (msg.type === 'private' && 
                        (msg.sender === ChatState.activeChatTab || msg.recipient === ChatState.activeChatTab)) {
                        displayMessage(msg);
                    }
                }
            });
            
        } catch (error) {
            console.error('Error polling messages:', error);
        }
    }

    // Stats Updating
    async function updateStats() {
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=getStats'
            });
            
            const stats = await response.json();
            document.getElementById('onlineUsers').textContent = stats.online_users;
            document.getElementById('totalVisits').textContent = stats.total_visits;
        } catch (error) {
            console.error('Error updating stats:', error);
        }
    }

    // Event Listeners
    sendButton.addEventListener('click', sendMessage);
    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') sendMessage();
    });

    // Start polling
    setInterval(pollMessages, 3000);
    setInterval(updateStats, 5000);
    pollMessages();
    updateStats();
    
    // Initialize first chat tab
    updateChatTabs();

    // Expose global functions
    window.ChatState = ChatState;
});
    </script>
	   <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/forge/1.3.0/forge.min.js"></script>
    <script>
        // RSA Key Generation (Client-Side)
        const { pki } = forge;
        const keyPair = pki.rsa.generateKeyPair({ bits: 2048 });
        const publicKey = pki.publicKeyToPem(keyPair.publicKey);
        const privateKey = pki.privateKeyToPem(keyPair.privateKey);
        
        // Store keys securely in sessionStorage
        sessionStorage.setItem('privateKey', privateKey);
        sessionStorage.setItem('publicKey', publicKey);
       
    </script>
</body>
</html>