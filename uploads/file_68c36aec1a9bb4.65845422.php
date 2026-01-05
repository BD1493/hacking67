<?php
/**********************
 * SIMPLE FILE HUB + CHAT (Single file)
 * - Admin password protects chat posting and file deletion
 * - Terms modal shown every visit
 * - Any file type upload (<=10MB)
 * - Comments + chat persisted in JSON files
 **********************/

// ====== CONFIG ======
$ADMIN_PASSWORD = 'sigma1493'; // <-- change me
$REDIRECT_IF_DISAGREE = 'https://usesafely.42web.io'; // where to send users who disagree
$UPLOAD_DIR = __DIR__ . '/uploads/';
$COMMENTS_FILE = __DIR__ . '/comments.json';
$CHAT_FILE = __DIR__ . '/chat.json';
$MAX_BYTES = 10 * 1024 * 1024; // 10MB

date_default_timezone_set('UTC');

// Ensure storage exists
if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0775, true);
foreach ([$COMMENTS_FILE, $CHAT_FILE] as $f) {
    if (!file_exists($f)) file_put_contents($f, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --------- Helpers ----------
function json_read($path) {
    $h = fopen($path, 'c+');
    if (!$h) return [];
    flock($h, LOCK_SH);
    $size = filesize($path);
    $raw = $size > 0 ? fread($h, $size) : '[]';
    flock($h, LOCK_UN);
    fclose($h);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_write($path, $data) {
    $h = fopen($path, 'c+');
    if (!$h) return false;
    flock($h, LOCK_EX);
    ftruncate($h, 0);
    fwrite($h, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    return true;
}

function is_image_ext($ext) {
    return in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp','bmp','svg' , 'html' , 'css' , 'js' , 'mp4' , 'gdoc', 'pdf', 'mp3']);
}

function safe_basename($name) {
    $name = basename($name);
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
}

function respond_json($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

// --------- AJAX endpoints ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Add a comment
    if ($action === 'add_comment') {
        $file = safe_basename($_POST['file'] ?? '');
        $text = trim($_POST['text'] ?? '');
        if ($file === '' || $text === '') respond_json(['ok'=>false,'error'=>'Missing file or text']);
        if (!file_exists($UPLOAD_DIR.$file)) respond_json(['ok'=>false,'error'=>'File not found']);
        $comments = json_read($COMMENTS_FILE);
        if (!isset($comments[$file])) $comments[$file] = [];
        $comments[$file][] = [
            'text' => mb_strimwidth($text, 0, 2000, '…', 'UTF-8'),
            'at' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        json_write($COMMENTS_FILE, $comments);
        respond_json(['ok'=>true]);
    }

    // Fetch comments
    if ($action === 'get_comments') {
        $file = safe_basename($_POST['file'] ?? '');
        $comments = json_read($COMMENTS_FILE);
        respond_json(['ok'=>true,'comments'=>$comments[$file] ?? []]);
    }

    // Post chat (admin password required)
    if ($action === 'chat_post') {
        $pwd = $_POST['password'] ?? '';
        if ($pwd !== $ADMIN_PASSWORD) respond_json(['ok'=>false,'error'=>'Invalid password']);
        $msg = trim($_POST['message'] ?? '');
        if ($msg === '') respond_json(['ok'=>false,'error'=>'Message empty']);
        $name = trim($_POST['name'] ?? 'Admin');
        $chat = json_read($CHAT_FILE);
        $chat[] = [
            'name' => mb_strimwidth($name, 0, 100, '', 'UTF-8'),
            'message' => mb_strimwidth($msg, 0, 2000, '…', 'UTF-8'),
            'at' => date('c')
        ];
        if (count($chat) > 200) $chat = array_slice($chat, -200);
        json_write($CHAT_FILE, $chat);
        respond_json(['ok'=>true]);
    }

    // Fetch chat
    if ($action === 'chat_fetch') {
        $chat = json_read($CHAT_FILE);
        respond_json(['ok'=>true,'chat'=>$chat]);
    }

    // Delete a file (admin password)
    if ($action === 'delete_file') {
        $pwd = $_POST['password'] ?? '';
        if ($pwd !== $ADMIN_PASSWORD) respond_json(['ok'=>false,'error'=>'Invalid password']);
        $file = safe_basename($_POST['file'] ?? '');
        $path = $UPLOAD_DIR.$file;
        if ($file === '' || !is_file($path)) respond_json(['ok'=>false,'error'=>'File not found']);
        if (@unlink($path)) {
            $comments = json_read($COMMENTS_FILE);
            if (isset($comments[$file])) {
                unset($comments[$file]);
                json_write($COMMENTS_FILE, $comments);
            }
            respond_json(['ok'=>true]);
        } else {
            respond_json(['ok'=>false,'error'=>'Delete failed']);
        }
    }

    respond_json(['ok'=>false,'error'=>'Unknown action']);
}

// --------- Upload form ----------
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "❌ Upload error code: ".$file['error'];
    } elseif ($file['size'] > $MAX_BYTES) {
        $message = "❌ File too large (max 10MB).";
    } else {
        $orig = safe_basename($file['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $newName = uniqid('file_', true) . ($ext ? '.'.$ext : '');
        if (move_uploaded_file($file['tmp_name'], $UPLOAD_DIR.$newName)) {
            $message = "✅ Upload successful!";
        } else {
            $message = "❌ Upload failed.";
        }
    }
}

// List files
$files = array_values(array_filter(scandir($UPLOAD_DIR), function($f){
    return $f !== '.' && $f !== '..' && is_file(__DIR__ . '/uploads/' . $f);
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>File Upload, Gallery & Admin Chat</title>
<style>
:root{--bg:#f0f2f5;--card:#fff;--ink:#111;--primary:#1d4ed8;--accent:#22c55e;--muted:#e5e7eb;--danger:#ef4444}
*{box-sizing:border-box} body{margin:0;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink)}
header{background:var(--primary);color:white;text-align:center;padding:20px;box-shadow:0 4px 8px rgba(0,0,0,0.1)}h1{margin:0;font-size:2rem}
.container{max-width:1100px;margin:20px auto;padding:0 12px;display:grid;grid-template-columns:2fr 1fr;gap:20px}
.card{background:var(--card);border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,0.06);padding:16px}
form.upload{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
input[type=file]{padding:10px;border-radius:10px;border:1px solid #ccc;background:#fff}
button,.btn{padding:10px 12px;border:none;border-radius:10px;font-weight:600;cursor:pointer;transition:filter .2s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.upload-btn{background:var(--accent);color:#fff}
.upload-btn:hover,.btn:hover{filter:brightness(1.08)}
.message{font-weight:bold;margin-top:5px}
.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:15px}
.gallery-item{background:var(--card);border-radius:12px;padding:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);transition:transform .3s;display:flex;flex-direction:column;gap:8px}
.gallery-item:hover{transform:translateY(-3px);box-shadow:0 6px 18px rgba(0,0,0,0.12)}
.gallery-item img{width:100%;border-radius:10px;max-height:180px;object-fit:cover}
.file-icon{width:100%;height:180px;display:flex;align-items:center;justify-content:center;background:var(--muted);border-radius:12px}
.file-link{display:block;word-wrap:break-word;color:var(--primary);text-decoration:none;font-weight:600}
.file-link:hover{text-decoration:underline}
.comment-box{width:100%;padding:8px;border:1px solid #ccc;border-radius:8px}
.comment-section{display:flex;flex-direction:column;gap:6px;text-align:left;max-height:140px;overflow-y:auto;background:#fafafa;border:1px solid #eee;border-radius:8px;padding:8px}
.comment-btn{background:#3b82f6;color:#fff}
.download-btn{background:var(--accent);color:#fff}
.delete-btn{background:var(--danger);color:#fff}
.small{font-size:12px;color:#555}
.side{display:flex;flex-direction:column;gap:20px}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999}
.modal{background:#fff;max-width:520px;width:92%;border-radius:14px;padding:18px}
.modal h2{margin:0 0 10px}
.modal p{line-height:1.5}
.modal .row{display:flex;gap:10px;margin-top:12px}
.modal .btn{flex:1}
.chat-box{display:flex;flex-direction:column;gap:10px;height:520px}
.chat-log{flex:1;overflow-y:auto;border:1px solid #eee;border-radius:10px;padding:10px;background:#fafafa}
.chat-inputs{display:grid;grid-template-columns:1fr 2fr;gap:8px}
.chat-inputs input,.chat-inputs textarea{width:100%;padding:8px;border:1px solid #ccc;border-radius:8px}
.chat-actions{display:flex;gap:8px}
hr{border:none;border-top:1px solid #eee;margin:10px 0}
</style>
</head>
<body>
<header><h1>File Upload, Gallery & Admin Chat</h1></header>
<div class="container">
<div class="card">
<h3>Upload a File (max 10MB, any type)</h3>
<form class="upload" method="post" enctype="multipart/form-data">
<input type="file" name="file" required>
<button type="submit" class="upload-btn">Upload File</button>
<?php if($message): ?>
<div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<div class="small">Tip: Admin can delete files (password required when deleting).</div>
</form>
<h3>Gallery</h3>
<div class="gallery" id="gallery">
<?php foreach($files as $file):
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $path = "uploads/".rawurlencode($file);
    $isImage = is_image_ext($ext);
?>
<div class="gallery-item" data-file="<?= htmlspecialchars($file) ?>">
<?php if($isImage): ?>
<img src="<?= $path ?>" alt="">
<?php else: ?>
<div class="file-icon">
<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="#1d4ed8" viewBox="0 0 24 24"><path d="M6 2h9l5 5v15a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm11 7h-5V4H6v16h12V9z"/></svg>
</div>
<?php endif; ?>
<a class="file-link" href="<?= $path ?>" download><?= htmlspecialchars($file) ?></a>
<div class="row" style="display:flex; gap:8px; flex-wrap:wrap;">
<a href="<?= $path ?>" download class="btn download-btn">Download</a>
<button class="btn delete-btn" data-delete>Delete (Admin)</button>
</div>
<div class="comment-section" id="comments-<?= md5($file) ?>"></div>
<div style="display:flex; gap:8px; margin-top:6px;">
<input class="comment-box" type="text" placeholder="Add a comment">
<button class="btn comment-btn">Comment</button>
</div>
</div>
<?php endforeach; ?>
</div>
</div>

<div class="side">
<div class="card">
<h3>Admin Chat (password required)</h3>
<div class="chat-box">
<div class="chat-log" id="chatLog"></div>
<div class="chat-inputs">
<input id="chatName" type="text" placeholder="Name (default: Admin)">
<textarea id="chatMsg" rows="3" placeholder="Type a message..."></textarea>
</div>
<div class="chat-actions">
<input id="chatPwd" type="password" placeholder="Admin password">
<button class="btn upload-btn" id="sendChat">Send</button>
</div>
</div>
<div class="small">Only users who know the admin password can send chat messages.</div>
</div>

<div class="card">
<h3>Terms of Use (Summary)</h3>
<ul class="small">
<li>No suing the site owner/operators.</li>
<li>No sharing of passwords or access codes.</li>
<li>Uploads must respect others’ rights; illegal content is prohibited.</li>
</ul>
<div class="small">The full terms appear as a popup every visit. Disagreeing will redirect you away.</div>
</div>
</div>
</div>

<div class="modal-backdrop" id="termsBackdrop" role="dialog" aria-modal="true" aria-labelledby="termsTitle">
<div class="modal">
<h2 id="termsTitle">Terms of Use Agreement</h2>
<p>By continuing, you agree: <br>• You will <b>not sue</b> the site owner/operators.<br>• You will <b>not share passwords</b> or access codes.<br>• You will not upload illegal or infringing content.</p>
<p class="small">If you disagree, you will be redirected.</p>
<div class="row">
<button class="btn" style="background:#9ca3af;color:#fff" id="disagreeBtn">Disagree</button>
<button class="btn upload-btn" id="agreeBtn">Agree & Continue</button>
</div>
</div>
</div>

<script>
const termsBackdrop = document.getElementById('termsBackdrop');
document.getElementById('agreeBtn').addEventListener('click', ()=>{termsBackdrop.style.display='none';});
document.getElementById('disagreeBtn').addEventListener('click', ()=>{window.location.href = <?= json_encode($REDIRECT_IF_DISAGREE) ?>;});

// Helpers
async function postForm(data){const res=await fetch(location.href,{method:'POST',body:data});return res.json();}
function el(sel,root=document){return root.querySelector(sel);}
function els(sel,root=document){return Array.from(root.querySelectorAll(sel));}
function escapeHTML(s){return s.replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}

// Comments
function loadCommentsFor(item){
    const file = item.dataset.file;
    const section = item.querySelector('.comment-section');
    const fd = new FormData();
    fd.append('action','get_comments');
    fd.append('file',file);
    postForm(fd).then(j=>{
        if(!j.ok) return;
        section.innerHTML='';
        (j.comments||[]).forEach(c=>{
            const div=document.createElement('div');
            const when=new Date(c.at).toLocaleString();
            div.innerHTML=`<b>${escapeHTML(c.ip||'')}</b> <span class="small">(${when})</span><br>${escapeHTML(c.text)}`;
            section.appendChild(div);
        });
    }).catch(()=>{});
}

els('.gallery-item').forEach(item=>{
    loadCommentsFor(item);
    const btn=item.querySelector('.comment-btn');
    const input=item.querySelector('.comment-box');
    btn.addEventListener('click',()=>{
        const text=input.value.trim();
        if(!text) return;
        const fd=new FormData();
        fd.append('action','add_comment');
        fd.append('file',item.dataset.file);
        fd.append('text',text);
        postForm(fd).then(j=>{
            if(j.ok){input.value='';loadCommentsFor(item);}
            else alert(j.error||'Failed to add comment');
        });
    });
});

// Delete file
els('[data-delete]').forEach(btn=>{
    btn.addEventListener('click',()=>{
        const item=btn.closest('.gallery-item');
        const file=item.dataset.file;
        const pwd=prompt('Admin password to delete this file:');
        if(!pwd) return;
        const fd=new FormData();
        fd.append('action','delete_file');
        fd.append('file',file);
        fd.append('password',pwd);
        postForm(fd).then(j=>{
            if(j.ok)item.remove();
            else alert(j.error||'Delete failed');
        });
    });
});

// Chat
const chatLog=document.getElementById('chatLog');
const chatName=document.getElementById('chatName');
const chatMsg=document.getElementById('chatMsg');
const chatPwd=document.getElementById('chatPwd');
const sendChat=document.getElementById('sendChat');

function renderChat(messages){
    chatLog.innerHTML='';
    (messages||[]).forEach(m=>{
        const when=new Date(m.at).toLocaleString();
        const div=document.createElement('div');
        div.style.padding='6px 8px';
        div.style.background='#fff';
        div.style.border='1px solid #eee';
        div.style.borderRadius='8px';
        div.style.marginBottom='8px';
        div.innerHTML=`<b>${escapeHTML(m.name||'')}</b> <span class="small">(${when})</span><br>${escapeHTML(m.message||'')}`;
        chatLog.appendChild(div);
    });
    chatLog.scrollTop=chatLog.scrollHeight;
}

async function fetchChat(){
    try{
        const fd=new FormData();
        fd.append('action','chat_fetch');
        const j=await postForm(fd);
        if(j.ok) renderChat(j.chat);
    }catch{}
}

sendChat.addEventListener('click',async()=>{
    const msg=chatMsg.value.trim();
    if(!msg) return;
    const fd=new FormData();
    fd.append('action','chat_post');
    fd.append('name',chatName.value.trim()||'Admin');
    fd.append('message',msg);
    fd.append('password',chatPwd.value);
    const j=await postForm(fd);
    if(j.ok){chatMsg.value='';fetchChat();}
    else alert(j.error||'Unable to send (check password).');
});

fetchChat();
setInterval(fetchChat,5000);
</script>
</body>
</html>
