<?php
/**
 * Live chat for the public site.
 *
 * The API on port 5000 has no chat routes, so Start chat was answered
 * with "Route not found". This script keeps the conversation on the site.
 */

const GF_STAFF_MS = 45000;
const GF_FALLBACK = 'Thanks for your message. Customer support is away right now. For a quote or inquiry, email sam.maina@globeflight.co.ke or call +254 729 341 277.';

function gf_chat_blank() {
    return array(
        'chats' => array(),
        'messages' => array(),
        'contacts' => array(),
        'presence' => array(),
        'hits' => array(),
    );
}

function gf_chat_clip($value, $limit) {
    $text = preg_replace('/\s+/u', ' ', (string) $value);
    $text = trim($text);
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
    }
    if (strlen($text) > $limit) {
        return rtrim(substr($text, 0, $limit - 1)) . '…';
    }
    return $text;
}

function gf_chat_next_id($list) {
    $max = 0;
    foreach ($list as $item) {
        $id = isset($item['id']) ? (int) $item['id'] : 0;
        if ($id > $max) $max = $id;
    }
    return $max + 1;
}

function gf_chat_email($value) {
    $email = strtolower(trim((string) $value));
    if ($email === '' || strlen($email) > 120) return '';
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) return '';
    return $email;
}

function gf_chat_phone($value) {
    $phone = preg_replace('/[^\d+]/', '', (string) $value);
    $phone = preg_replace('/(?!^)\+/', '', $phone);
    $digits = preg_replace('/\D/', '', $phone);
    $count = strlen($digits);
    if ($count < 9 || $count > 15) return '';
    return $phone;
}

function gf_chat_staff_online(&$db) {
    $now = (int) round(microtime(true) * 1000);
    $online = false;
    foreach ($db['presence'] as $id => $seen) {
        if ($now - (int) $seen > GF_STAFF_MS) {
            unset($db['presence'][$id]);
            continue;
        }
        $online = true;
    }
    return $online;
}

function gf_chat_messages_for($db, $chatId) {
    $rows = array();
    foreach ($db['messages'] as $item) {
        if (!is_array($item)) continue;
        if ((int) (isset($item['chatId']) ? $item['chatId'] : 0) === (int) $chatId) $rows[] = $item;
    }
    usort($rows, function ($a, $b) {
        $time = strcmp((string) (isset($a['createdAt']) ? $a['createdAt'] : ''), (string) (isset($b['createdAt']) ? $b['createdAt'] : ''));
        if ($time !== 0) return $time;
        return (int) (isset($a['id']) ? $a['id'] : 0) - (int) (isset($b['id']) ? $b['id'] : 0);
    });
    return $rows;
}

function gf_chat_public_message($message) {
    if (!is_array($message)) {
        return array('id' => 0, 'from' => '', 'text' => '', 'authorName' => '', 'createdAt' => '');
    }
    return array(
        'id' => isset($message['id']) ? (int) $message['id'] : 0,
        'from' => isset($message['from']) ? (string) $message['from'] : '',
        'text' => isset($message['text']) ? (string) $message['text'] : '',
        'authorName' => isset($message['authorName']) ? (string) $message['authorName'] : '',
        'createdAt' => isset($message['createdAt']) ? (string) $message['createdAt'] : '',
    );
}

function gf_chat_find_token($db, $token) {
    $token = (string) $token;
    if (strlen($token) < 20) return null;
    foreach ($db['chats'] as $index => $chat) {
        if (isset($chat['token']) && hash_equals((string) $chat['token'], $token)) {
            return $index;
        }
    }
    return null;
}

function gf_chat_thread($db, $chat, $token) {
    $messages = gf_chat_messages_for($db, $chat['id']);
    $messages = array_slice($messages, -80);
    $public = array();
    foreach ($messages as $message) $public[] = gf_chat_public_message($message);
    $payload = array(
        'id' => (int) $chat['id'],
        'visitorName' => isset($chat['visitorName']) ? $chat['visitorName'] : '',
        'visitorEmail' => isset($chat['visitorEmail']) ? $chat['visitorEmail'] : '',
        'visitorPhone' => isset($chat['visitorPhone']) ? $chat['visitorPhone'] : '',
        'hasContact' => !empty($chat['visitorEmail']) && !empty($chat['visitorPhone']),
        'handler' => gf_chat_staff_online($db) ? 'staff' : 'ai',
        'staffOnline' => gf_chat_staff_online($db),
        'messages' => $public,
    );
    if ($token) $payload['token'] = $token;
    return $payload;
}

function gf_chat_save_contact(&$db, $chat) {
    $row = null;
    foreach ($db['contacts'] as $index => $item) {
        if ((int) $item['chatId'] === (int) $chat['id']) {
            $row = $index;
            break;
        }
    }
    $now = gmdate('c');
    if ($row === null) {
        $db['contacts'][] = array(
            'id' => gf_chat_next_id($db['contacts']),
            'chatId' => (int) $chat['id'],
            'name' => isset($chat['visitorName']) ? $chat['visitorName'] : '',
            'email' => $chat['visitorEmail'],
            'phone' => $chat['visitorPhone'],
            'createdAt' => $now,
            'updatedAt' => $now,
        );
        return;
    }
    $db['contacts'][$row]['name'] = isset($chat['visitorName']) ? $chat['visitorName'] : '';
    $db['contacts'][$row]['email'] = $chat['visitorEmail'];
    $db['contacts'][$row]['phone'] = $chat['visitorPhone'];
    $db['contacts'][$row]['updatedAt'] = $now;
}

function gf_chat_allow_message(&$db, $key) {
    $now = time();
    $recent = array();
    if (isset($db['hits'][$key]) && is_array($db['hits'][$key])) {
        foreach ($db['hits'][$key] as $time) {
            if ($now - (int) $time < 300) $recent[] = (int) $time;
        }
    }
    if (count($recent) >= 12) {
        $db['hits'][$key] = $recent;
        return false;
    }
    $recent[] = $now;
    $db['hits'][$key] = $recent;
    return true;
}

function gf_chat_assistant($history) {
    if (getenv('GF_CHAT_TEST') === '1') return GF_FALLBACK;
    $key = trim((string) getenv('GROQ_API_KEY'));
    if ($key === '' || !function_exists('curl_init')) return GF_FALLBACK;
    $messages = array(array('role' => 'system', 'content' => gf_chat_facts()));
    $history = array_slice($history, -8);
    foreach ($history as $message) {
        $role = (isset($message['from']) && $message['from'] === 'visitor') ? 'user' : 'assistant';
        $content = (string) $message['text'];
        if (isset($message['from']) && $message['from'] === 'staff') {
            $content = 'Customer support said: ' . $content;
        }
        $messages[] = array('role' => $role, 'content' => $content);
    }
    $payload = json_encode(array(
        'model' => 'openai/gpt-oss-20b',
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 400,
    ));
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ),
    ));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return GF_FALLBACK;
    $json = json_decode($raw, true);
    $text = '';
    if (isset($json['choices'][0]['message']['content'])) {
        $text = (string) $json['choices'][0]['message']['content'];
    }
    $text = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text);
    $text = trim($text);
    return $text !== '' ? $text : GF_FALLBACK;
}

function gf_chat_alert($chat, $message) {
    if (getenv('GF_CHAT_TEST') === '1') return;
    $last = isset($chat['lastAlertAt']) ? strtotime($chat['lastAlertAt']) : 0;
    if ($last && time() - $last < 600) return;
    $lines = array(
        'Someone is in the live chat now.',
        'Sign in to the admin dashboard and open Live chat.',
        '',
        'Name: ' . gf_chat_clip(isset($chat['visitorName']) ? $chat['visitorName'] : 'Not given', 140),
        'Email: ' . gf_chat_clip(isset($chat['visitorEmail']) ? $chat['visitorEmail'] : 'Not given', 140),
        'Phone: ' . gf_chat_clip(isset($chat['visitorPhone']) ? $chat['visitorPhone'] : 'Not given', 140),
    );
    $wrote = trim((string) $message);
    if ($wrote !== '') {
        $lines[] = '';
        $lines[] = 'They wrote:';
        $lines[] = $wrote;
    }
    $body = implode("\n", $lines);
    $headers = "From: Globeflight Kenya <cs@globeflight.co.ke>\r\nReply-To: globeflightke21@gmail.com\r\nContent-Type: text/plain; charset=UTF-8";
    foreach (gf_chat_alert_addresses() as $address) {
        @mail($address, 'Someone is waiting in the live chat', $body, $headers);
    }
}

function gf_chat_alert_addresses() {
    return array('sam.maina@globeflight.co.ke', 'globeflightke21@gmail.com');
}

function gf_chat_fail($status, $message) {
    return array($status, array('success' => false, 'message' => $message));
}

function gf_chat_dispatch(&$db, $method, $op, $id, $body, $token, $staff) {
    if (!is_array($db)) $db = gf_chat_blank();
    foreach (array('chats', 'messages', 'contacts', 'hits') as $key) {
        if (!isset($db[$key]) || !is_array($db[$key])) $db[$key] = array();
    }
    if (!isset($db['presence']) || !is_array($db['presence'])) $db['presence'] = array();

    if ($method === 'GET' && $op === '') {
        $index = gf_chat_find_token($db, $token);
        if ($index === null) {
            return array(200, array('success' => true, 'data' => array(
                'staffOnline' => gf_chat_staff_online($db),
                'messages' => array(),
                'handler' => gf_chat_staff_online($db) ? 'staff' : 'ai',
            )));
        }
        return array(200, array('success' => true, 'data' => gf_chat_thread($db, $db['chats'][$index], null)));
    }

    if ($method === 'POST' && $op === 'start') {
        $email = gf_chat_email(isset($body['email']) ? $body['email'] : '');
        $phone = gf_chat_phone(isset($body['phone']) ? $body['phone'] : '');
        if ($email === '') return gf_chat_fail(400, 'Enter a valid email address.');
        if ($phone === '') return gf_chat_fail(400, 'Enter a phone number.');
        $name = gf_chat_clip(isset($body['name']) ? $body['name'] : '', 60);
        $now = gmdate('c');
        $index = gf_chat_find_token($db, $token);
        $created = null;
        if ($index === null) {
            $created = bin2hex(random_bytes(32));
            $db['chats'][] = array(
                'id' => gf_chat_next_id($db['chats']),
                'token' => $created,
                'visitorName' => $name,
                'visitorEmail' => $email,
                'visitorPhone' => $phone,
                'handler' => 'ai',
                'staffUnread' => 1,
                'createdAt' => $now,
                'updatedAt' => $now,
            );
            $index = count($db['chats']) - 1;
            gf_chat_save_contact($db, $db['chats'][$index]);
            gf_chat_alert($db['chats'][$index], '');
            $db['chats'][$index]['lastAlertAt'] = $now;
        }
        return array(200, array('success' => true, 'data' => gf_chat_thread($db, $db['chats'][$index], $created)));
    }

    if ($method === 'POST' && $op === 'messages') {
        $index = gf_chat_find_token($db, $token);
        $key = $index === null ? 'visitor' : (string) $db['chats'][$index]['token'];
        if (!gf_chat_allow_message($db, $key)) {
            return gf_chat_fail(429, 'Please wait a moment before sending another message.');
        }
        $text = gf_chat_clip(isset($body['text']) ? $body['text'] : '', 1000);
        if ($text === '') return gf_chat_fail(400, 'Write a message first.');
        if ($index === null || empty($db['chats'][$index]['visitorEmail']) || empty($db['chats'][$index]['visitorPhone'])) {
            return gf_chat_fail(400, 'Enter your email and phone number before chatting.');
        }
        $chat = $db['chats'][$index];
        $name = gf_chat_clip(isset($body['name']) ? $body['name'] : '', 60);
        if ($name !== '' && empty($chat['visitorName'])) $chat['visitorName'] = $name;
        $now = gmdate('c');
        $db['messages'][] = array(
            'id' => gf_chat_next_id($db['messages']),
            'chatId' => (int) $chat['id'],
            'from' => 'visitor',
            'text' => $text,
            'authorName' => !empty($chat['visitorName']) ? $chat['visitorName'] : 'Visitor',
            'createdAt' => $now,
        );
        $thread = gf_chat_messages_for($db, $chat['id']);
        if (count($thread) > 200) {
            $drop = array();
            foreach (array_slice($thread, 0, count($thread) - 200) as $old) $drop[(int) $old['id']] = true;
            $kept = array();
            foreach ($db['messages'] as $item) {
                if ((int) $item['chatId'] === (int) $chat['id'] && isset($drop[(int) $item['id']])) continue;
                $kept[] = $item;
            }
            $db['messages'] = $kept;
        }
        $chat['updatedAt'] = $now;
        $chat['staffUnread'] = (int) (isset($chat['staffUnread']) ? $chat['staffUnread'] : 0) + 1;
        if (gf_chat_staff_online($db)) {
            $chat['handler'] = 'staff';
        } else {
            $chat['handler'] = 'ai';
            $reply = gf_chat_assistant(array_slice(gf_chat_messages_for($db, $chat['id']), -8));
            $db['messages'][] = array(
                'id' => gf_chat_next_id($db['messages']),
                'chatId' => (int) $chat['id'],
                'from' => 'ai',
                'text' => $reply,
                'authorName' => 'Globeflight Assistant',
                'createdAt' => gmdate('c'),
            );
            $chat['updatedAt'] = gmdate('c');
            gf_chat_alert($chat, $text);
            $chat['lastAlertAt'] = $chat['updatedAt'];
        }
        $db['chats'][$index] = $chat;
        return array(200, array('success' => true, 'data' => gf_chat_thread($db, $chat, null)));
    }

    if (!$staff) {
        $message = 'Sign in to answer the live chat.';
        if (!empty($GLOBALS['gf_chat_staff_message'])) $message = (string) $GLOBALS['gf_chat_staff_message'];
        return gf_chat_fail(401, $message);
    }

    if ($method === 'POST' && $op === 'presence') {
        $db['presence'][(string) $staff['id']] = (int) round(microtime(true) * 1000);
        return array(200, array('success' => true, 'data' => array('staffOnline' => true)));
    }

    if ($method === 'GET' && $op === 'inbox') {
        gf_chat_staff_online($db);
        $chats = $db['chats'];
        usort($chats, function ($a, $b) {
            return strcmp((string) $b['updatedAt'], (string) $a['updatedAt']);
        });
        $chats = array_slice($chats, 0, 40);
        $rows = array();
        foreach ($chats as $chat) {
            $messages = gf_chat_messages_for($db, $chat['id']);
            $last = count($messages) ? $messages[count($messages) - 1] : null;
            $publicMessages = array();
            foreach (array_slice($messages, -40) as $message) $publicMessages[] = gf_chat_public_message($message);
            $rows[] = array(
                'id' => (int) $chat['id'],
                'visitorName' => !empty($chat['visitorName']) ? $chat['visitorName'] : 'Visitor',
                'visitorEmail' => isset($chat['visitorEmail']) ? $chat['visitorEmail'] : '',
                'visitorPhone' => isset($chat['visitorPhone']) ? $chat['visitorPhone'] : '',
                'staffUnread' => (int) (isset($chat['staffUnread']) ? $chat['staffUnread'] : 0),
                'updatedAt' => $chat['updatedAt'],
                'preview' => $last ? gf_chat_clip($last['text'], 90) : '',
                'lastFrom' => $last ? $last['from'] : '',
                'messages' => $publicMessages,
            );
        }
        return array(200, array('success' => true, 'data' => array(
            'staffOnline' => gf_chat_staff_online($db),
            'chats' => $rows,
        )));
    }

    if ($method === 'GET' && $op === 'contacts') {
        $rows = $db['contacts'];
        usort($rows, function ($a, $b) {
            return strcmp((string) $b['createdAt'], (string) $a['createdAt']);
        });
        $public = array();
        foreach ($rows as $row) {
            $public[] = array(
                'id' => (int) $row['id'],
                'chatId' => (int) $row['chatId'],
                'name' => isset($row['name']) ? $row['name'] : '',
                'email' => $row['email'],
                'phone' => $row['phone'],
                'createdAt' => $row['createdAt'],
            );
        }
        return array(200, array('success' => true, 'data' => $public));
    }

    if ($method === 'GET' && $op === 'thread') {
        $chatIndex = gf_chat_find_id($db, $id);
        if ($chatIndex === null) return gf_chat_fail(404, 'Chat not found.');
        $db['chats'][$chatIndex]['staffUnread'] = 0;
        return array(200, array('success' => true, 'data' => gf_chat_staff_thread($db, $db['chats'][$chatIndex])));
    }

    if ($method === 'POST' && $op === 'reply') {
        $text = gf_chat_clip(isset($body['text']) ? $body['text'] : '', 1000);
        if ($text === '') return gf_chat_fail(400, 'Write a reply first.');
        if ($id === '' && isset($body['chatId'])) $id = (string) $body['chatId'];
        $chatIndex = gf_chat_find_id($db, $id);
        if ($chatIndex === null) return gf_chat_fail(404, 'Chat not found.');
        $now = gmdate('c');
        $author = gf_chat_clip(isset($staff['fullName']) ? $staff['fullName'] : '', 60);
        if ($author === '') $author = 'Customer support';
        $db['messages'][] = array(
            'id' => gf_chat_next_id($db['messages']),
            'chatId' => (int) $db['chats'][$chatIndex]['id'],
            'from' => 'staff',
            'text' => $text,
            'authorName' => $author,
            'createdAt' => $now,
        );
        $db['chats'][$chatIndex]['handler'] = 'staff';
        $db['chats'][$chatIndex]['staffUnread'] = 0;
        $db['chats'][$chatIndex]['updatedAt'] = $now;
        $db['presence'][(string) $staff['id']] = (int) round(microtime(true) * 1000);
        return array(200, array('success' => true, 'data' => gf_chat_staff_thread($db, $db['chats'][$chatIndex])));
    }

    return gf_chat_fail(404, 'Not found.');
}

function gf_chat_find_id($db, $id) {
    foreach ($db['chats'] as $index => $chat) {
        if ((string) $chat['id'] === (string) $id) return $index;
    }
    return null;
}

function gf_chat_staff_thread($db, $chat) {
    $messages = array_slice(gf_chat_messages_for($db, $chat['id']), -80);
    $public = array();
    foreach ($messages as $message) $public[] = gf_chat_public_message($message);
    return array(
        'id' => (int) $chat['id'],
        'visitorName' => !empty($chat['visitorName']) ? $chat['visitorName'] : 'Visitor',
        'visitorEmail' => isset($chat['visitorEmail']) ? $chat['visitorEmail'] : '',
        'visitorPhone' => isset($chat['visitorPhone']) ? $chat['visitorPhone'] : '',
        'staffOnline' => gf_chat_staff_online($db),
        'messages' => $public,
    );
}

function gf_chat_facts() {
    return 'You are the Globeflight Kenya website assistant. You reply only while customer support is away. Answer basic questions about the company from the facts below. Use plain words. '
        . 'Globeflight Kenya is a logistics company in Nairobi, also called Globeflight Worldwide Express. Office: NEXTGEN MALL, 3rd Floor, Suite 39/40, Mombasa Road. Warehouse: Semco Business Park, Mombasa Road, Mlolongo. '
        . 'Hours: Monday to Friday 8:00 to 17:00, Saturday 9:00 to 13:00, Nairobi time. Customer service: cs@globeflight.co.ke. Quotes: sam.maina@globeflight.co.ke. Phone and WhatsApp: +254 729 341 277. '
        . 'Services: air freight, sea freight, customs clearance, international express, consolidation, warehousing, Big Drop Kenya, importer and exporter of record, and regional road transport with Union Green Logistics Ltd. '
        . 'Do not invent prices, duty, or a delivery date. If the question is not covered, say customer support will confirm and give sam.maina@globeflight.co.ke and the phone number. Keep the reply short. No headings.';
}

function gf_chat_store_file() {
    $candidates = array(dirname(__DIR__) . '/gf-chat-private', __DIR__ . '/gf-chat-private');
    foreach ($candidates as $dir) {
        if (is_dir($dir) && is_writable($dir)) return $dir . '/chat.json';
    }
    foreach ($candidates as $dir) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) continue;
        if (strpos($dir, __DIR__) === 0) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (is_writable($dir)) return $dir . '/chat.json';
    }
    return '';
}

function gf_chat_bearer($value) {
    $value = trim((string) $value);
    if ($value === '') return '';
    if (stripos($value, 'Bearer ') !== 0) $value = 'Bearer ' . $value;
    if (!preg_match('/^Bearer\s+\S+$/i', $value)) return '';
    return $value;
}

function gf_chat_authorization_candidates() {
    $preferred = array();
    $fallback = array();
    $headerBags = array();
    if (function_exists('getallheaders')) $headerBags[] = getallheaders();
    if (function_exists('apache_request_headers')) $headerBags[] = apache_request_headers();
    foreach ($headerBags as $headers) {
        if (!is_array($headers)) continue;
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'x-gf-admin') $preferred[] = $value;
            if ($lower === 'authorization') $fallback[] = $value;
        }
    }
    foreach (array('HTTP_X_GF_ADMIN', 'REDIRECT_HTTP_X_GF_ADMIN') as $key) {
        if (!empty($_SERVER[$key])) $preferred[] = $_SERVER[$key];
        $env = getenv($key);
        if (is_string($env) && $env !== '') $preferred[] = $env;
    }
    if (isset($_COOKIE['gf_admin'])) $preferred[] = $_COOKIE['gf_admin'];
    if (isset($_GET['gf_admin'])) $preferred[] = $_GET['gf_admin'];
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization') as $key) {
        if (!empty($_SERVER[$key])) $fallback[] = $_SERVER[$key];
        $env = getenv($key);
        if (is_string($env) && $env !== '') $fallback[] = $env;
    }
    $ready = array();
    foreach (array_merge($preferred, $fallback) as $value) {
        $bearer = gf_chat_bearer($value);
        if ($bearer !== '' && !in_array($bearer, $ready, true)) $ready[] = $bearer;
    }
    return $ready;
}

function gf_chat_authorization() {
    $ready = gf_chat_authorization_candidates();
    return count($ready) ? $ready[0] : '';
}

function gf_chat_profile_user($json) {
    if (!is_array($json)) return null;
    if (isset($json['user']) && is_array($json['user'])) return $json['user'];
    if (isset($json['data']['user']) && is_array($json['data']['user'])) return $json['data']['user'];
    if (isset($json['data']['role'])) return $json['data'];
    if (isset($json['role'])) return $json;
    return null;
}

function gf_chat_fetch_profile($url, $auth) {
    $result = gf_chat_fetch_profile_result($url, $auth);
    return $result['user'];
}

function gf_chat_fetch_profile_result($url, $auth) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array(
            'Authorization: ' . $auth,
            'Accept: application/json',
            'Origin: https://globeflight.co.ke',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 2,
    ));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) return array('status' => 0, 'user' => null);
    $user = $code === 200 ? gf_chat_profile_user(json_decode($raw, true)) : null;
    return array('status' => $code, 'user' => $user);
}

function gf_chat_staff_cache_path() {
    $store = gf_chat_store_file();
    if ($store === '') return '';
    return dirname($store) . '/staff-cache.json';
}

function gf_chat_staff_cache_read() {
    $path = gf_chat_staff_cache_path();
    if ($path === '' || !is_file($path)) return array();
    $json = json_decode((string) file_get_contents($path), true);
    return is_array($json) ? $json : array();
}

function gf_chat_staff_cache_write($cache) {
    $path = gf_chat_staff_cache_path();
    if ($path === '') return;
    file_put_contents($path, json_encode($cache));
}

function gf_chat_cached_staff($auth, $maxAge) {
    $cache = gf_chat_staff_cache_read();
    $key = hash('sha256', $auth);
    if (!isset($cache[$key]) || !is_array($cache[$key])) return null;
    $row = $cache[$key];
    $seen = isset($row['seen']) ? (int) $row['seen'] : 0;
    if (time() - $seen > $maxAge) return null;
    if (isset($row['user']) && is_array($row['user'])) return $row['user'];
    return null;
}

function gf_chat_remember_staff($auth, $user) {
    $cache = gf_chat_staff_cache_read();
    $now = time();
    $keep = array();
    foreach ($cache as $key => $row) {
        if (!is_array($row)) continue;
        $seen = isset($row['seen']) ? (int) $row['seen'] : 0;
        if ($now - $seen > 7 * 86400) continue;
        $keep[$key] = $row;
    }
    $keep[hash('sha256', $auth)] = array('seen' => $now, 'user' => $user);
    gf_chat_staff_cache_write($keep);
}

function gf_chat_accept_staff($user) {
    if (!is_array($user)) return null;
    $role = strtolower(trim((string) (isset($user['role']) ? $user['role'] : '')));
    if ($role !== 'admin' && $role !== 'superadmin') return null;
    if (!isset($user['id']) || $user['id'] === '') $user['id'] = $role;
    return $user;
}

function gf_chat_staff_from_request() {
    $GLOBALS['gf_chat_staff_message'] = '';
    $candidates = gf_chat_authorization_candidates();
    if (!count($candidates) || !function_exists('curl_init')) return null;
    foreach ($candidates as $auth) {
        $cached = gf_chat_cached_staff($auth, 12 * 3600);
        if ($cached) return $cached;
    }
    $rateLimited = false;
    foreach ($candidates as $auth) {
        $user = null;
        $limited = false;
        foreach (array('http://127.0.0.1:5000/admin/api/auth/profile', 'https://globeflight.co.ke/admin/api/auth/profile') as $url) {
            $result = gf_chat_fetch_profile_result($url, $auth);
            if ($result['status'] === 429) {
                $limited = true;
                continue;
            }
            if ($result['user']) {
                $user = $result['user'];
                break;
            }
        }
        if ($limited) $rateLimited = true;
        $user = gf_chat_accept_staff($user);
        if ($user) {
            gf_chat_remember_staff($auth, $user);
            return $user;
        }
        if ($limited) {
            $stale = gf_chat_cached_staff($auth, 7 * 86400);
            if ($stale) return $stale;
        }
    }
    if ($rateLimited) {
        $GLOBALS['gf_chat_staff_message'] = 'You are signed in, but the dashboard is limiting checks. Wait a few minutes, then open Live chat again.';
    }
    return null;
}

function gf_chat_json($payload) {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($payload, $flags);
    if ($json === false) return '';
    return $json;
}

function gf_chat_request_chat_id($queryId, $body) {
    $found = array();
    if (isset($_GET['chat'])) $found[] = $_GET['chat'];
    if ($queryId !== '') $found[] = $queryId;
    if (is_array($body) && isset($body['chatId'])) $found[] = $body['chatId'];
    $headerBags = array();
    if (function_exists('getallheaders')) $headerBags[] = getallheaders();
    if (function_exists('apache_request_headers')) $headerBags[] = apache_request_headers();
    foreach ($headerBags as $headers) {
        if (!is_array($headers)) continue;
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-gf-chat') $found[] = $value;
        }
    }
    foreach (array('HTTP_X_GF_CHAT', 'REDIRECT_HTTP_X_GF_CHAT') as $key) {
        if (!empty($_SERVER[$key])) $found[] = $_SERVER[$key];
        $env = getenv($key);
        if (is_string($env) && $env !== '') $found[] = $env;
    }
    foreach ($found as $value) {
        $value = trim((string) $value);
        if (preg_match('/^\d+$/', $value)) return $value;
    }
    return '';
}

function gf_chat_respond($status, $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $json = gf_chat_json($payload);
    if ($json === '') $json = '{"success":false,"message":"The chat could not be read."}';
    echo $json;
    exit;
}

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Allow: GET, POST, OPTIONS');
        exit;
    }
    $op = isset($_GET['op']) ? (string) $_GET['op'] : '';
    $raw = file_get_contents('php://input');
    if ($raw === false) $raw = '';
    if (strlen($raw) > 20000) gf_chat_respond(400, array('success' => false, 'message' => 'That message is too long.'));
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = array();
    $queryId = isset($_GET['id']) ? (string) $_GET['id'] : '';
    $id = gf_chat_request_chat_id($queryId, $body);
    $token = isset($_SERVER['HTTP_X_CHAT_TOKEN']) ? (string) $_SERVER['HTTP_X_CHAT_TOKEN'] : '';
    $staffOps = array('presence', 'inbox', 'contacts', 'thread', 'reply');
    $staff = in_array($op, $staffOps, true) ? gf_chat_staff_from_request() : null;
    $file = gf_chat_store_file();
    if ($file === '') gf_chat_respond(500, array('success' => false, 'message' => 'The chat could not be saved.'));
    $handle = fopen($file, 'c+');
    if (!$handle) gf_chat_respond(500, array('success' => false, 'message' => 'The chat could not be saved.'));
    flock($handle, LOCK_EX);
    $stored = stream_get_contents($handle);
    if ($stored === false) $stored = '';
    $db = json_decode($stored, true);
    $decoded = is_array($db);
    if (!$decoded) {
        if (trim($stored) !== '') {
            flock($handle, LOCK_UN);
            fclose($handle);
            gf_chat_respond(500, array('success' => false, 'message' => 'The saved chats could not be read.'));
        }
        $db = gf_chat_blank();
    }
    $result = gf_chat_dispatch($db, $method, $op, $id, $body, $token, $staff);
    $storedJson = gf_chat_json($db);
    if ($storedJson === '') {
        flock($handle, LOCK_UN);
        fclose($handle);
        gf_chat_respond(500, array('success' => false, 'message' => 'The chat could not be saved.'));
    }
    if ($storedJson !== $stored) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $storedJson);
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    gf_chat_respond($result[0], $result[1]);
}
