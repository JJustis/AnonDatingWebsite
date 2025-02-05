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
            
            // Create tables if they don't exist
            $this->initDatabase();
            
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
        
        $this->currentUser = isset($_SESSION['username']) ? $_SESSION['username'] : 'anonymous_' . session_id();
    }
    
    private function initDatabase() {
        $tables = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(16) UNIQUE NOT NULL,
                session_id VARCHAR(255) NOT NULL,
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
        ";
        
        foreach (explode(';', $tables) as $table) {
            if (trim($table)) {
                $this->db->exec($table);
            }
        }
    }

    public function handleCommand($input) {
        $parts = explode(' ', trim($input));
        $command = strtolower($parts[0]);

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

            default:
                return "Invalid command. Available commands: /username, /msg, /img, /live";
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
        if($target === 'all') {
            if(strpos($message, 'key') !== false) {
                list($msg, $key) = explode('key', $message);
                $encryptedMsg = base64_encode(trim($msg) . trim($key));
                return $this->broadcastMessage($encryptedMsg, trim($key));
            }
            return $this->broadcastMessage($message);
        }
        
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$target]);
        
        if($stmt->rowCount() > 0) {
            $stmt = $this->db->prepare("INSERT INTO messages (sender, recipient, content) VALUES (?, ?, ?)");
            $stmt->execute([$this->currentUser, $target, $message]);
            return ["status" => "success", "message" => "Message sent to " . $target];
        }
        return ["status" => "error", "message" => "User not found"];
    }

    private function handleImageUpload($target) {
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

    private function broadcastMessage($message, $key = null) {
        $stmt = $this->db->prepare("INSERT INTO public_messages (sender, content, encryption_key) VALUES (?, ?, ?)");
        $stmt->execute([$this->currentUser, $message, $key]);
        return ["status" => "success", "message" => "Message broadcasted" . ($key ? " with key: " . $key : "")];
    }

    public function getMessages() {
        $messages = [];
        
        // Get public messages
        $stmt = $this->db->query("SELECT * FROM public_messages ORDER BY sent_at DESC LIMIT 50");
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = [
                'type' => 'public',
                'sender' => $row['sender'],
                'content' => $row['content'],
                'key' => $row['encryption_key'],
                'time' => $row['sent_at']
            ];
        }
        
        // Get private messages for current user
        if(isset($_SESSION['username'])) {
            $stmt = $this->db->prepare("
                SELECT * FROM messages 
                WHERE recipient = ? OR sender = ?
                ORDER BY sent_at DESC LIMIT 50
            ");
            $stmt->execute([$this->currentUser, $this->currentUser]);
            
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $messages[] = [
                    'type' => 'private',
                    'sender' => $row['sender'],
                    'recipient' => $row['recipient'],
                    'content' => $row['content'],
                    'time' => $row['sent_at']
                ];
            }
        }
        
        usort($messages, function($a, $b) {
            return strtotime($b['time']) - strtotime($a['time']);
        });
        
        return $messages;
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
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
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
        
        .chat-container {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--accent-color);
            border-radius: 15px;
            height: 70vh;
            overflow-y: auto;
        }
        
        .message {
            background: rgba(201, 165, 92, 0.1);
            border-left: 3px solid var(--accent-color);
            margin: 10px 0;
            padding: 15px;
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
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row mb-5">
            <div class="col-12 text-center">
                <h1 class="brand-heading display-1 mb-4">ENIGMA</h1>
                <p class="lead mb-5">Where Mystery Meets Connection</p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
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
                            <p class="small mb-0">Set your anonymous identity</p>
                        </li>
                        <li class="mb-3">
                            <code class="command">/msg username message</code>
                            <p class="small mb-0">Send private message</p>
                        </li>
                        <li class="mb-3">
                            <code class="command">/msg all message</code>
                            <p class="small mb-0">Send public message</p>
                        </li>
                        <li class="mb-3">
                            <code class="command">/msg img username</code>
                            <p class="small mb-0">Share an image</p>
                        </li>
                        <li class="mb-3">
                            <code class="command">/msg live username</code>
                            <p class="small mb-0">Request live video chat</p>
                        </li>
                    </ul>
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
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chatMessages');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');
    const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
    const videoModal = new bootstrap.Modal(document.getElementById('videoModal'));
    let currentTarget = null;
    
    // Message handling
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
                if (input.startsWith('/img')) {
                    currentTarget = input.split(' ')[1];
                    imageModal.show();
                } else if (input.startsWith('/live')) {
                    currentTarget = input.split(' ')[1];
                    initializeVideoChat(result.room);
                }
            }
            
            displayMessage({
                type: 'system',
                content: result.message || result.error
            });
            
        } catch (error) {
            console.error('Error:', error);
            displayMessage({
                type: 'error',
                content: 'An error occurred while sending the message.'
            });
        }
    }
    
    // Display messages in chat
    function displayMessage(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'message';
        
        let content = '';
        if (message.type === 'private') {
            content = `<span class="username">${message.sender} → ${message.recipient}</span>`;
        } else if (message.type === 'public') {
            content = `<span class="username">${message.sender} → all</span>`;
        } else {
            content = `<span class="username">System</span>`;
        }
        
        content += `<p class="mb-0">${message.content}</p>`;
        if (message.time) {
            content += `<small class="text-muted">${new Date(message.time).toLocaleString()}</small>`;
        }
        
        messageDiv.innerHTML = content;
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Image upload handling
    document.getElementById('uploadButton').addEventListener('click', async function() {
        const formData = new FormData(document.getElementById('uploadForm'));
        formData.append('action', 'upload');
        formData.append('target', currentTarget);
        
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            imageModal.hide();
            displayMessage({
                type: 'system',
                content: result.message
            });
        } catch (error) {
            console.error('Error:', error);
            displayMessage({
                type: 'error',
                content: 'Failed to upload image.'
            });
        }
    });
    
    // Video chat handling
    let peerConnection = null;
    let localStream = null;
    
    async function initializeVideoChat(roomId) {
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
            document.getElementById('localVideo').srcObject = localStream;
            
            peerConnection = new RTCPeerConnection({
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
            });
            
            localStream.getTracks().forEach(track => {
                peerConnection.addTrack(track, localStream);
            });
            
            peerConnection.ontrack = event => {
                document.getElementById('remoteVideo').srcObject = event.streams[0];
            };
            
            videoModal.show();
        } catch (error) {
            console.error('Error accessing media devices:', error);
            displayMessage({
                type: 'error',
                content: 'Failed to access camera/microphone.'
            });
        }
    }
    
    // Event listeners
    sendButton.addEventListener('click', sendMessage);
    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') sendMessage();
    });
    
    document.getElementById('endCallButton').addEventListener('click', function() {
        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
        }
        if (peerConnection) {
            peerConnection.close();
        }
        videoModal.hide();
    });
    
    // Poll for new messages
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
            result.messages.forEach(displayMessage);
            
        } catch (error) {
            console.error('Error polling messages:', error);
        }
    }
    
    // Poll every 3 seconds
    setInterval(pollMessages, 3000);
    pollMessages(); // Initial poll
});
</script>
</body>
</html>