<?php
putenv('GF_CHAT_TEST=1');
require dirname(__DIR__) . '/gf-chat.php';

$failures = 0;

function check($label, $condition) {
    global $failures;
    if ($condition) {
        echo "ok  $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n";
}

$db = gf_chat_blank();
$badEmail = gf_chat_dispatch($db, 'POST', 'start', '', array(
    'name' => 'Asha',
    'email' => 'not-an-email',
    'phone' => '+254700000000',
), '', null);
check('bad email is rejected', $badEmail[0] === 400 && $badEmail[1]['message'] === 'Enter a valid email address.');

$badPhone = gf_chat_dispatch($db, 'POST', 'start', '', array(
    'email' => 'asha@example.com',
    'phone' => '123',
), '', null);
check('short phone is rejected', $badPhone[0] === 400);

$started = gf_chat_dispatch($db, 'POST', 'start', '', array(
    'name' => 'Asha',
    'email' => 'Asha@Example.com',
    'phone' => '+254 729 341 277',
), '', null);
$token = $started[1]['data']['token'] ?? '';
check('start chat succeeds', $started[0] === 200 && $started[1]['success'] === true);
check('start chat returns a token', strlen($token) >= 20);
check('contact is saved', $started[1]['data']['hasContact'] === true);
check('email is stored in lower case', $started[1]['data']['visitorEmail'] === 'asha@example.com');

$read = gf_chat_dispatch($db, 'GET', '', '', array(), $token, null);
check('the same chat can be read', $read[0] === 200 && $read[1]['data']['hasContact'] === true);

$sent = gf_chat_dispatch($db, 'POST', 'messages', '', array('text' => 'I need a quote'), $token, null);
$messages = $sent[1]['data']['messages'] ?? array();
check('a message is accepted', $sent[0] === 200 && count($messages) === 2);
check('the assistant replies while support is away', isset($messages[1]['from']) && $messages[1]['from'] === 'ai');

$staff = array('id' => 7, 'fullName' => 'Sam Maina', 'role' => 'admin');
gf_chat_dispatch($db, 'POST', 'presence', '', array(), '', $staff);
$duringStaff = gf_chat_dispatch($db, 'POST', 'messages', '', array('text' => 'Are you there?'), $token, null);
$during = $duringStaff[1]['data']['messages'] ?? array();
$last = $during[count($during) - 1];
check('no assistant reply while support is here', $last['from'] === 'visitor' && $last['text'] === 'Are you there?');

$inbox = gf_chat_dispatch($db, 'GET', 'inbox', '', array(), '', $staff);
check('support can see the chat', $inbox[0] === 200 && count($inbox[1]['data']['chats']) === 1);
$inboxMessages = $inbox[1]['data']['chats'][0]['messages'] ?? array();
check('the inbox includes the visitor message', count($inboxMessages) >= 1 && $inboxMessages[count($inboxMessages) - 1]['text'] === 'Are you there?');

$chatId = (string) $inbox[1]['data']['chats'][0]['id'];
$opened = gf_chat_dispatch($db, 'GET', 'thread', $chatId, array(), '', $staff);
$openedMessages = $opened[1]['data']['messages'] ?? array();
check('opening a chat shows its messages', $opened[0] === 200 && count($openedMessages) >= 1 && $openedMessages[count($openedMessages) - 1]['text'] === 'Are you there?');
$_GET['chat'] = $chatId;
check('the chat number is read from chat', gf_chat_request_chat_id('', array()) === $chatId);
unset($_GET['chat']);
$_SERVER['HTTP_X_GF_CHAT'] = $chatId;
check('the chat number is read from the header', gf_chat_request_chat_id('', array()) === $chatId);
unset($_SERVER['HTTP_X_GF_CHAT']);
check('the chat number is read from the reply', gf_chat_request_chat_id('', array('chatId' => $chatId)) === $chatId);
$reply = gf_chat_dispatch($db, 'POST', 'reply', $chatId, array('text' => 'Yes, how can we help?'), '', $staff);
$replied = $reply[1]['data']['messages'] ?? array();
$staffMessage = $replied[count($replied) - 1];
check('support can reply', $reply[0] === 200 && $staffMessage['from'] === 'staff' && $staffMessage['authorName'] === 'Sam Maina');

$locked = gf_chat_dispatch($db, 'GET', 'inbox', '', array(), '', null);
check('the inbox stays closed without a sign-in', $locked[0] === 401);

$contacts = gf_chat_dispatch($db, 'GET', 'contacts', '', array(), '', $staff);
check('the contact is listed', $contacts[0] === 200 && $contacts[1]['data'][0]['email'] === 'asha@example.com');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer dashboard-token';
check('dashboard sign-in is read', gf_chat_authorization() === 'Bearer dashboard-token');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirected-token';
check('rewritten sign-in is read', gf_chat_authorization() === 'Bearer redirected-token');
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$_SERVER['HTTP_X_GF_ADMIN'] = 'dashboard-token';
check('dashboard header is read', gf_chat_authorization() === 'Bearer dashboard-token');
unset($_SERVER['HTTP_X_GF_ADMIN']);
$_GET['gf_admin'] = 'query-token';
check('query sign-in is read', gf_chat_authorization() === 'Bearer query-token');
unset($_GET['gf_admin']);
$_COOKIE['gf_admin'] = 'cookie-token';
check('cookie sign-in is read', gf_chat_authorization() === 'Bearer cookie-token');
unset($_COOKIE['gf_admin']);
check('missing sign-in is refused', gf_chat_authorization() === '');
$alerts = gf_chat_alert_addresses();
check('chat alerts go to the gmail inbox', in_array('globeflightke21@gmail.com', $alerts, true));
check('chat alerts no longer use the customer service inbox', !in_array('cs@globeflight.co.ke', $alerts, true));

$profile = gf_chat_profile_user(array('user' => array('id' => 4, 'role' => 'superadmin', 'fullName' => 'Sam')));
check('profile user is read', is_array($profile) && $profile['role'] === 'superadmin');

exit($failures === 0 ? 0 : 1);
