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

$chatId = (string) $inbox[1]['data']['chats'][0]['id'];
$reply = gf_chat_dispatch($db, 'POST', 'reply', $chatId, array('text' => 'Yes, how can we help?'), '', $staff);
$replied = $reply[1]['data']['messages'] ?? array();
$staffMessage = $replied[count($replied) - 1];
check('support can reply', $reply[0] === 200 && $staffMessage['from'] === 'staff' && $staffMessage['authorName'] === 'Sam Maina');

$locked = gf_chat_dispatch($db, 'GET', 'inbox', '', array(), '', null);
check('the inbox stays closed without a sign-in', $locked[0] === 401);

$contacts = gf_chat_dispatch($db, 'GET', 'contacts', '', array(), '', $staff);
check('the contact is listed', $contacts[0] === 200 && $contacts[1]['data'][0]['email'] === 'asha@example.com');

exit($failures === 0 ? 0 : 1);
