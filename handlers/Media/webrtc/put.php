<?php

function Media_webrtc_put($params = array()) {
    $params = array_merge($_REQUEST, $params);

    $loggedUserId = Users::loggedInUser(true)->id;
    $publisherId = Q::ifset($params, 'publisherId', null);
    $streamName = Q::ifset($params, 'streamName', null);
    $participantSid = Q::ifset($params, 'participantSid', null);

    if(Q_Request::slotName('updateLog')) {
        $publisherId = Q::ifset($params, 'publisherId', null);
        $roomId = Q::ifset($params, 'roomId', null);
        $participant = Q::ifset($params, 'participant', null);
        $toSave = Q::ifset($params, 'log', null);

        if($toSave == null) {
            Q_Response::setSlot("updateLog", null);
            return;
        }

        $logsDirectory = str_replace('/', DS, Q_Config::get('Q', 'logs', 'directory', 'Q/logs'));
        $logsPath = (defined('APP_FILES_DIR') ? APP_FILES_DIR : Q_FILES_DIR).DS.$logsDirectory.DS.'webrtc';

        // ro#931: the room is fetched as the logged-in user, who must be able
        // to join it (it was fetched as the request's publisherId, so anyone
        // wrote logs for any room), and every path segment is built from
        // checked values. The folder came from "roomId" and the file name
        // from "participant" split on a tab, both unsanitised: any logged-in
        // user wrote a .log file anywhere PHP can write, and could mkdir
        // there too.
        $streamName = "Media/webrtc/$roomId";
        $stream = Streams_Stream::fetch($loggedUserId, $publisherId, $streamName, true);
        if (!$stream->testWriteLevel('join')) {
            throw new Users_Exception_NotAuthorized();
        }
        $startTime = date('YmdHis', round($stream->getAttribute('startTime') / 1000));

        // '-' rather than '_', which removeOldLogs() splits the date on
        $folderName = preg_replace('/[^A-Za-z0-9-]/', '-', $roomId) . '_' . $startTime;

        $path = $logsPath . DS . $folderName;
        // WebRTC.js sends "<loggedInUserId>\t<room start time>": the user is
        // the logged-in one, and the time is digits only
        $participantInfo = preg_split('/\t/', (string)$participant);
        $participantTime = preg_replace('/[^0-9]/', '', (string)Q::ifset($participantInfo, 1, ''));
        $filename = preg_replace('/[^A-Za-z0-9-]/', '-', $loggedUserId) . '_' . $participantTime . '.log';
        $mask = umask(0000);

        if (!file_exists($path)) {
            if (!@mkdir($path, 0777, true)) {
                throw new Q_Exception_FilePermissions(array('action' => 'create', 'filename' => $path, 'recommendation' => ' Please set the app files directory to be writable.'));
            }
        }

        $toSave = file_exists($path.DS.$filename) && filesize($path.DS.$filename)
			? ',' . PHP_EOL . trim($toSave,"[]")
			: trim($toSave,"[]");
        $result = file_put_contents($path.DS.$filename, $toSave, FILE_APPEND);


        try {
            removeOldLogs($logsPath);
        } catch (Exception $e) {

        }

        umask($mask);

		Q_Response::setSlot("updateLog", $toSave);
	}
}

function deleteDir($dir) {
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it,
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($dir);
}
//remove logs that are older than 7 days
function removeOldLogs($logsPath) {
    $dirs = array_filter(glob($logsPath . '/*'), 'is_dir');
    foreach ($dirs as $dir) {
        $dirInfo = pathinfo($dir);
        $logsDate = explode('_', $dirInfo['basename'])[1];
        $timestump = strtotime($logsDate);
        $path = $dirInfo['dirname'] . DS . $dirInfo['basename'] ;

        if(time() - $timestump > 604800) {
            deleteDir($path);
        }
    }
}
