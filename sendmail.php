<?php

DEFINE ('INCLUDE_DIR', 'include');
DEFINE ('COMMON_FILE', 'common.php');

class db
{

    private $mysqli;

    public function __construct($config)
    {
        $this->mysqli = new MySQLi($config['dbhost'], $config['dbuser'], $config['dbpass'], $config['dbname']);
        if ($this->mysqli->connect_errno) {
            throw new mysqli_sql_exception('Can not connect to MySQL: (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error);
        }
        $this->mysqli->set_charset("utf8");
    }

    public function query($query)
    {
        //$query = $this->mysqli->real_escape_string($query);

        $result = $this->mysqli->query($query);

        if (!$result) {
            echo 'Can not done the query (' . $this->mysqli->errno . ") " . $this->mysqli->error;
        }

        return $result;
    }

    public function getMessagesToSend()
    {
        $result = NULL;
        $getMessagesListQuery = "SELECT * FROM cb_mail_queue WHERE 1";

        $result = $this->query($getMessagesListQuery);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getSMTPServersList()
    {
        $result = NULL;
        $getServersListQuery = "SELECT * FROM cb_smtp WHERE 1";

        $result = $this->query($getServersListQuery);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getAttachedFiles($mailId)
    {
        $result = NULL;
        $getFilesListQuery = "SELECT * FROM cb_mail_files_temporary WHERE mail_id = $mailId";

        $result = $this->query($getFilesListQuery);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function moveMessageToArchive($mailId)
    {
        $messageToMove = $this->getMessageFromQueue($mailId);

        if ($this->addMessageToArchive($messageToMove)) {
            if ($this->removeMessageFromQueue($mailId)) {
                return true;
            }
        }
    }

    public function getMessageFromQueue($mailId)
    {
        $result = NULL;
        $getMessageQuery = "SELECT * FROM cb_mail_queue WHERE id = $mailId";

        $result = $this->query($getMessageQuery);

        return $result->fetch_assoc();
    }

    public function removeMessageFromQueue($mailId)
    {
        $result = NULL;
        $getMessageQuery = "DELETE FROM cb_mail_queue WHERE id = $mailId";

        $result = $this->query($getMessageQuery);

        return $result;
    }


    public function addMessageToArchive($message)
    {
        // there are no some fields in cb_mail_archive table

        unset($message['smtp_server_id']);
        $message['sended_from'] = $message['from_mail'];
        $message['sended_time'] = date("Y-m-d H:i:s");
        $message['error_type'] = 0;
        $message['error_text'] = '';

        $result = sql_insert('cb_mail_archive', $message); //using internal clientbase.ru function to avoid problems with quotes

        return $result;
    }

    public function addErrorCodeToArchive($mailId, $errorText)
    {
        $result = NULL;

        $updateErrorQuery = "UPDATE cb_mail_archive SET error_type = 10, error_text = '$errorText' WHERE id=$mailId";

        $result = $this->query($updateErrorQuery);

        return $result;
    }

    public function addTextToLog($mailId, $success = FALSE)
    {
        $message = $this->getMessageFromQueue($mailId);
        $threadId = $message['thread_id'];

        $getFormQuery = "SELECT forms.table_id FROM cb_forms forms, cb_mail_threads threads WHERE forms.id = threads.form_id AND threads.id = $threadId";
        $getFormQueryResult = $this->query($getFormQuery);
        $form = $getFormQueryResult->fetch_assoc();

        $tableId = $form['table_id'];
        $fieldId = $form['log_mail'];
        $lineId  = $message['line_id'];

        $formName = $form['name_form'];
        $dateText = date("Y-m-d H:i:s");
        $successText = $success? 'SENDED': 'FAIL';

        $logText = "$formName, $dateText - $successText";  // Form name, 18.12.2015 15:46:13 - SENDED/FAIL

        $setLogTextQuery = "UPDATE cb_data$tableId SET f$fieldId = CONCAT(f$fieldId, '\n', $logText) WHERE id = $lineId";

        return $this->query($setLogTextQuery);
    }

    public function deleteFilesFromQueue($mailId)
    {
        $result = NULL;
        $deleteFilesQuery = "DELETE FROM cb_mail_files_temporary WHERE mail_id = $mailId";

        $result = $this->query($deleteFilesQuery);

        return $result;
    }

    public function updateThread($threadId, $increaseSent = FALSE, $increaseFail = FALSE)
    {
        $result = NULL;

        $increaseSentQuery = "UPDATE cb_mail_threads SET sended = sended + 1 WHERE id = $threadId";
        $increaseFailQuery = "UPDATE cb_mail_threads SET failed = failed + 1 WHERE id = $threadId";
        $decreaseWaitQuery = "UPDATE cb_mail_threads SET wait = wait - 1 WHERE id = $threadId";

        if ($increaseSent) {
            $increaseQuery = $increaseSentQuery;
        } elseif ($increaseFail) {
            $increaseQuery = $increaseFailQuery;
        }

        if (isset($increaseQuery) && $this->query($increaseQuery)) {
            $result = $this->query($decreaseWaitQuery);
        }

        return $result;
    }
}

function showMainPage($csrf, $content = NULL)
{
    showPageHeader($csrf);
    showPageContent($content);
    showPageFooter();
}

function showPageHeader($csrf) {
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- The above 3 meta tags *must* come first in the head; any other head content must come *after* these tags -->
        <meta name="description" content="">
        <meta name="author" content="">
        <link rel="icon" href="/favicon.ico">

        <title>Files upload addon</title>
    </head>
    <body>
    </body>
    </html>
    <?php
}

function showPageContent($content) {
    if ($content) echo $content;
}

function showPageFooter() {
    ?>
    </body>
    </html>
    <?php
}

function sendMessage($messageToSend, $smtpServer, $attachedFiles = []) {
    $mail = new PHPMailer;

    $mail->SMTPDebug = 3;

    $mail->isSMTP();                                      // Set mailer to use SMTP
    $mail->Host = $smtpServer['smtp_host'];               // Specify main and backup SMTP servers
    $mail->SMTPAuth = true;                               // Enable SMTP authentication
    $mail->SMTPKeepAlive = true;                          // SMTP connection will not close after each email sent, reduces SMTP overhead
    $mail->Username = $smtpServer['username'];            // SMTP username
    $mail->Password = $smtpServer['password'];            // SMTP password
    $mail->SMTPSecure = $smtpServer['secutiry'];          // Enable TLS encryption, `ssl` also accepted
    $mail->Port = $smtpServer['smtp_post'];               // TCP port to connect to

    $mail->setFrom($messageToSend['from_mail'], $messageToSend['from_name']);
    $emailsList = explode(',', $messageToSend['email']);
    foreach ($emailsList as $email) {
        $mail->addAddress(trim($email));           // Add a recipient
    }
    $mail->addReplyTo($messageToSend['reply_to'], $messageToSend['from_name']);

    if ($attachedFiles) {
        foreach ($attachedFiles as $attachedFile) {
            $mail->AddStringAttachment($attachedFile['content'], $attachedFile['name'], 'base64', $attachedFile['type']);
        }
    }

    $mail->isHTML(true);                                  // Set email format to HTML
    $mail->CharSet = $messageToSend['charset'];

    $mail->Subject = $messageToSend['subject'];
    $mail->Body    = $messageToSend['body'];

    if(!$mail->send()) {
        return $mail->ErrorInfo;
    } else {
        return true;
    }
}

$content = '';

$commonFilePath = INCLUDE_DIR . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . COMMON_FILE;
if (file_exists($commonFilePath)) include_once($commonFilePath);

if (!isset($user)) {
    die('only authorized users allowed');
}

if (!isset($config)) {
    die('$config variable is not set');
}

foreach ($_POST as $postVarName=>$postVarValue) {
    $$postVarName = $postVarValue;
}

foreach ($_GET as $getVarName=>$getVarValue) {
    $$getVarName = $getVarValue;
}


try {
    $DB = new DB($config);
} catch (Exception $e) {
    echo $e->getMessage();
    die();
}

$smtpServersList = $DB->getSMTPServersList();
$messagesToSendList = $DB->getMessagesToSend();

if ($messagesToSendList) {
    foreach($messagesToSendList as $messageToSend) {
        echo $messageToSend['id'] . PHP_EOL;
        $smtpServer = $smtpServersList[array_search($messageToSend['from_mail'], array_column($smtpServersList, 'name'))];
        $attachedFiles = $DB->getAttachedFiles($messageToSend['id']);
        $sendMessageResult = sendMessage($messageToSend, $smtpServer, $attachedFiles);
        if ($sendMessageResult !== TRUE) {
            if ($DB->moveMessageToArchive($messageToSend['id'])) {
                $DB->addErrorCodeToArchive($messageToSend['id'], $sendMessageResult);
                $DB->updateThread($messageToSend['thread_id'], FALSE, TRUE);
            }

            echo "Error sending message ", $sendMessageResult . PHP_EOL;
        } else {
            if ($DB->moveMessageToArchive($messageToSend['id'])) {
                $DB->deleteFilesFromQueue($messageToSend['id']);
                $DB->updateThread($messageToSend['thread_id'], TRUE, FALSE);
            }

            echo "Message sent" . PHP_EOL;
        }
    }
} else {
    echo "Nothing to send" . PHP_EOL;
}

echo "Done" . PHP_EOL;

//showMainPage($csrf, $content);
