<?php

/**
 * @module Media
 */

/**
 * Used to start a new Media/webrtc stream (a real time audio/video call)
 * Handler to which requests from front-end are made. Both manager.js and client.js send requests to this handler. 
 * @class HTTP Media webrtc
 * @method post
 * @param {array} [$_REQUEST] Parameters that can come from the request
 *   @param {string} $_REQUEST.publisherId  Required. The id of the user to publish the stream.
 *   @param {string} $_REQUEST.roomId Pass an ID for the room from the client, may already exist
 *   @param {string} $_REQUEST.closeManually If true, stream is not closed automatically by node.js
 * @return {void}
 */
function Media_callCenter_post($params = array())
{
    $config = Q_Config::get('Media', 'webrtc', Q_Config::get('Media', 'webrtc', array()));
    $options = Q::take($config, array(
        'socketServerHost' => '',
        'socketServerPort' => null,
        'turnServers' => array(),
        'useTwilioTurn' => array(),
        'livestreaming' => array(),
        'globalLimits' => array(),
        'debug' => false
    ));
    extract($options);
    $socketServerHost = Q::ifset($config, 'socketServerHost', '');
    $socketServerHost = trim(str_replace('/(http\:\/\/) || (https\:\/\/)/', '', $socketServerHost), '/');
    if(!empty($socketServerHost) && !empty($socketServerPort)){
        $socketServer = $socketServerHost . ':' . $socketServerPort;
    } else {
        $socketServer = trim(str_replace('/(http\:\/\/) || (https\:\/\/)/', '', Q_Config::expect('Q', 'node', 'url')), '/');
    }

	$params = array_merge($_REQUEST, $params);
    $publisherId = Q::ifset($params, 'publisherId', null);
	$streamName = Q::ifset($params, 'streamName', null);
	$roomId = Q::ifset($params, 'roomId', null);
	$socketId = Q::ifset($params, 'socketId', null);
	$operatorSocketId = Q::ifset($params, 'operatorSocketId', null);
	$callDescription = Q::ifset($params, 'description', null);
	$resumeClosed = Q::ifset($params, 'resumeClosed', null);
	$relate = Q::ifset($params, 'relate', null);
	$content = Q::ifset($params, 'content', null);
    $writeLevel = Q::ifset($params, 'writeLevel', 23);
    $closeManually = Q::ifset($params, 'closeManually', null);
    $useRelatedTo = Q::ifset($params, 'useRelatedTo', null);
    $cmd = Q::ifset($params, 'cmd', null);

    if(Q_Request::slotName('data')) {
        if($cmd == 'closeStream') {
            //print_r($_REQUEST);die;
            Q_Valid::signature(true, $_REQUEST);
            $webrtcStream = Streams_Stream::fetch($publisherId, $publisherId, $streamName);
    
            if(!is_null($webrtcStream)) {
                //return Q_Response::setSlot('data', ['publisherId' => $publisherId, 'streamName' => get_class_methods($webrtcStream)]);
                $webrtcStream->close($publisherId);
                $webrtcStream->changed();
                //$webrtcStream->save();
            }
    
            return Q_Response::setSlot('data', ['cmd'=> $cmd, 'publisherId' => $publisherId, 'streamName' => $streamName, 'req' => $_REQUEST]);
        } else if($cmd == 'closeIfOffline') {
            Q_Valid::signature(true, $_REQUEST);

            $userIsOnline = Q::ifset($params, 'userIsOnline', false);
            $webrtcStream = Streams_Stream::fetch(null, $publisherId, $streamName);
    
            $streamWasClosed = false;
            if($userIsOnline === false || $userIsOnline === 'false' || $userIsOnline === 0 || $userIsOnline === '0') {
                $webrtcStream->close($publisherId);
                $webrtcStream->changed();
                //$webrtcStream->save();
                $streamWasClosed = true;
            }
    
            return Q_Response::setSlot('data', ['cmd'=> $cmd, 'streamWasClosed' =>  $streamWasClosed, 'userIsOnline' => $userIsOnline, 'publisherId' => $publisherId, 'streamName' => $streamName]);
        }

        return Q_Response::setSlot('data', 'unknown command');
    }

    $loggedInUser = Users::loggedInUser(true);
	$loggedInUserId = $loggedInUser->id;
	// ro#931: every slot below acts as the logged-in user. The request's
	// "publisherId" only names a target stream (makeCallCenterFromStream,
	// closeIfOffline); it is never the user who posts, closes or publishes.
	// The data slot above is Node's, signature-checked; these are browser
	// requests, so they need the nonce (Q_Response checks it for AJAX only).
	Q_Valid::nonce(true);
	$publisherId = Q::ifset($params, 'publisherId', $loggedInUserId);
    if(Q_Request::slotName('makeCallCenterFromStream')) {
        // makeCallCenterFromStream means giving access to this stream for Users/hosts

        $webrtcStream = Streams_Stream::fetch($loggedInUserId, $publisherId, $streamName, true);

        // ro#931: this check was inverted (`if (!testAdminLevel('manage'))`),
        // so the Users/hosts row was written on streams the caller does NOT
        // manage, and never on the manager's own. Only a manager may open a
        // stream to the publisher's Users/hosts.
        if(!$webrtcStream->testAdminLevel('manage')) {
            throw new Users_Exception_NotAuthorized();
        }
        $access = new Streams_Access();
        $access->publisherId = $publisherId;
        $access->streamName = $streamName;
        $access->ofContactLabel = 'Users/hosts';
        if (!$access->retrieve()) {
            $access->readLevel = Streams::$READ_LEVEL['max'];
            $access->writeLevel = Streams::$WRITE_LEVEL['max'];
            $access->adminLevel = Streams::$ADMIN_LEVEL['invite'];
            $access->save();
        }

        return Q_Response::setSlot("makeCallCenterFromStream", 'done');
    } else if(Q_Request::slotName('closeIfOffline')) {
        if ($loggedInUser) {
            // ro#931: both branches end with the stream closed as its
            // publisher (here, or in the signed data slot after Node reports
            // the socket offline, and a caller can name any socketId), which
            // skips Streams::close()'s own check. So any logged-in user
            // closed any stream. Require the call's own type and the close
            // write level as the caller first. A call center's operators
            // have it: the call is related to the call center with
            // inheritAccess (room slot below).
            $webrtcStream = Streams_Stream::fetch($loggedInUserId, $publisherId, $streamName, true);
            if ($webrtcStream->type !== 'Media/webrtc') {
                throw new Q_Exception_WrongType(array(
                    'field' => 'streamName',
                    'type' => 'a Media/webrtc stream'
                ));
            }
            if (!$webrtcStream->testWriteLevel('close')) {
                throw new Users_Exception_NotAuthorized();
            }
            if(is_null($socketId)) {
                $webrtcStream->close($loggedInUserId);
                $webrtcStream->changed();
            } else {
                // check if any participants in the call are online
                Q_Utils::sendToNode(array(
                    "Q/method" => "Users/checkIfOnline",
                    "socketId" => $socketId,
                    "userId" => $publisherId,
                    "operatorSocketId" => $operatorSocketId,
                    "operatorUserId" => $loggedInUserId,
                    "handlerToExecute" => 'Media/callCenter',
                    "data" => [
                        "cmd" => 'closeIfOffline',
                        "publisherId" => $publisherId,
                        "streamName" => $streamName
                    ],
                ));
            }
            
        }
    
        return Q_Response::setSlot("closeIfOffline", 'done');
    } else if(Q_Request::slotName('acceptHandler')) {
        $waitingRoom = Q::ifset($params, 'waitingRoom', null);
        $liveShowRoom = Q::ifset($params, 'liveShowRoom', null);
        $callStatus = Q::ifset($params, 'callStatus', 'none');

        if(!$waitingRoom || !$liveShowRoom) {
            throw new Exception('waitingRoom and liveShowRoom are required');
        }

        list($waitingRoomStream, $liveShowRoomStream) = Media_callCenter_post_rooms($loggedInUserId, $waitingRoom, $liveShowRoom);

        $status = $waitingRoomStream->getAttribute('status');

        //print_r($status);
        //print_r('---');
        //print_r($callStatus);die;
        if($status != $callStatus) {
            throw new Exception('Another manager is interviewing or already accepted this call');
        }

        $access = new Streams_Access();
        $access->publisherId = $liveShowRoom['publisherId'];
        $access->streamName = $liveShowRoom['streamName'];
        $access->ofUserId = $waitingRoom['publisherId'];
        if (!$access->retrieve()) {
            $access->readLevel = Streams::$READ_LEVEL['max'];
            $access->writeLevel = Streams::$WRITE_LEVEL['relate'];
            $access->adminLevel = Streams::$ADMIN_LEVEL['invite'];
            $access->save();
        }
    
        $waitingRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/accepted',
            'instructions' => [
                'msg' => 'Your call request was accepted'
            ]
        ));

        $waitingRoomStream->setAttribute('status', 'accepted');
        $waitingRoomStream->setAttribute('acceptedByUserId', $loggedInUserId);
        $waitingRoomStream->changed();
        //$waitingRoomStream->save();

        $liveShowRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/accepted',
            'instructions' => [
                'waitingRoom' => $waitingRoom,
                'byUserId' => $loggedInUserId
            ]
        ));

        return Q_Response::setSlot("acceptHandler", 'request sent');
    } else if(Q_Request::slotName('endOrDeclineCallHandler')) {
        $waitingRoom = Q::ifset($params, 'waitingRoom', null);
        $liveShowRoom = Q::ifset($params, 'liveShowRoom', null);
        $action = Q::ifset($params, 'action', null);
        $callStatus = Q::ifset($params, 'callStatus', 'none');

        if(!$waitingRoom || !$liveShowRoom || !$action) {
            throw new Exception('$waitingRoom, $liveShowRoom and $action are required');
        }

        list($waitingRoomStream, $liveShowRoomStream) = Media_callCenter_post_rooms($loggedInUserId, $waitingRoom, $liveShowRoom);

        $status = $waitingRoomStream->getAttribute('status');

        if($status != $callStatus) {
            throw new Exception('Another manager already accepted, declined or ended this call');
        }

        $access = new Streams_Access();
        $access->publisherId = $liveShowRoom['publisherId'];
        $access->streamName = $liveShowRoom['streamName'];
        $access->ofUserId = $waitingRoom['publisherId'];
        if ($access->retrieve()) {
            $access->remove();
        }

        $instructions = [
            'immediate' => true, 
            'userId' => $waitingRoom['publisherId']
        ];
        $messageType = 'Media/webrtc/callEnded';
        if($action == 'endCall') {
            $messageType = 'Media/webrtc/callEnded';
            $instructions['msg'] = 'Call ended';
        } else if($action == 'declineCall') {
            $messageType = 'Media/webrtc/callDeclined';
            $instructions['msg'] = 'Your call request was declined';
        }
    
        $waitingRoomStream->post($loggedInUserId, array(
            'type' => $messageType,
            'instructions' => $instructions
        ));

        $waitingRoomStream->setAttribute('status', $action == 'endCall' ? 'ended' : 'declined');
        $waitingRoomStream->setAttribute('endedOrDeclinedByUserId', $loggedInUserId);
        $waitingRoomStream->changed();
        //$waitingRoomStream->save();

        $liveShowRoomStream->post($loggedInUserId, array(
            'type' => $messageType,
            'instructions' => [
                'waitingRoom' => $waitingRoom,
                'byUserId' => $loggedInUserId
            ]
        ));

        // Still closed as its publisher, but only after
        // Media_callCenter_post_rooms() checked that the caller manages the
        // call center and that this waiting room is a call placed with it.
        $waitingRoomStream->close($waitingRoom['publisherId']);

        return Q_Response::setSlot("endOrDeclineCallHandler", 'done');
    } else if (Q_Request::slotName('interviewHandler')) {
        $waitingRoom = Q::ifset($params, 'waitingRoom', null);
        $liveShowRoom = Q::ifset($params, 'liveShowRoom', null);
        $callStatus = Q::ifset($params, 'callStatus', 'none');

        if(!$waitingRoom || !$liveShowRoom) {
            throw new Exception('$waitingRoom, $liveShowRoom and $action are required');
        }

        list($waitingRoomStream, $liveShowRoomStream) = Media_callCenter_post_rooms($loggedInUserId, $waitingRoom, $liveShowRoom);

        $status = $waitingRoomStream->getAttribute('status');

        if($status != $callStatus) {
            throw new Exception('Another manager already changed status of this call');
        }

        $waitingRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/interview',
            'instructions' => ['msg' => 'Operator started interview with you']
        ));

        $waitingRoomStream->setAttribute('status', 'interview');
        $waitingRoomStream->setAttribute('interviewedByUserId', $loggedInUserId);
        $waitingRoomStream->changed();
        //$waitingRoomStream->save();

        $liveShowRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/interview',
            'instructions' => [
                'waitingRoom' => $waitingRoom,
                'byUserId' => $loggedInUserId
            ]
        ));
        return Q_Response::setSlot("interviewHandler", 'done');
    } else if (Q_Request::slotName('markApprovedHandler')) {
        $waitingRoom = Q::ifset($params, 'waitingRoom', null);
        $liveShowRoom = Q::ifset($params, 'liveShowRoom', null);
        $isApproved = Q::ifset($params, 'isApproved', null);
        $isApproved = $isApproved == 'true' ? true : false;

        //var_dump($isApproved);die;
        if(!$waitingRoom || !$liveShowRoom) {
            throw new Exception('$waitingRoom, $liveShowRoom and $action are required');
        }

        list($waitingRoomStream, $liveShowRoomStream) = Media_callCenter_post_rooms($loggedInUserId, $waitingRoom, $liveShowRoom);

        $waitingRoomStream->setAttribute('isApproved', $isApproved);
        $waitingRoomStream->setAttribute('isApprovedByUserId', $loggedInUserId);
        $waitingRoomStream->changed();
        //$waitingRoomStream->save();

        $liveShowRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/approved',
            'instructions' => [
                'waitingRoom' => $waitingRoom,
                'byUserId' => $loggedInUserId,
                'isApproved' => $isApproved
            ]
        ));
        return Q_Response::setSlot("markApprovedHandler", 'done');
    } else if (Q_Request::slotName('holdHandler')) {
        $waitingRoom = Q::ifset($params, 'waitingRoom', null);
        $liveShowRoom = Q::ifset($params, 'liveShowRoom', null);
        $onHold = Q::ifset($params, 'onHold', null);
        $onHold = $onHold == 'true' ? true : false;

        if(!$waitingRoom || !$liveShowRoom) {
            throw new Exception('$waitingRoom, $liveShowRoom and $action are required');
        }

        list($waitingRoomStream, $liveShowRoomStream) = Media_callCenter_post_rooms($loggedInUserId, $waitingRoom, $liveShowRoom);

        $waitingRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/hold',
            'instructions' => [
                'msg' => 'Your call was put on hold...',
                'userId' => $waitingRoom['publisherId']
            ]
        ));

        $waitingRoomStream->setAttribute('onHold', $onHold);
        $waitingRoomStream->setAttribute('putOnHoldByUserId', $loggedInUserId);
        $waitingRoomStream->setAttribute('status', 'created');
        //$waitingRoomStream->changed();
        $waitingRoomStream->save();

        $liveShowRoomStream->post($loggedInUserId, array(
            'type' => 'Media/webrtc/hold',
            'instructions' => [
                'waitingRoom' => $waitingRoom,
                'byUserId' => $loggedInUserId,
                'onHold' => $onHold
            ]
        ));
        return Q_Response::setSlot("holdHandler", 'done');
    } else {
        if (!$socketId) {
            throw new Exception('To continue you should be connected to the socket server.');
        }

        if($useTwilioTurn) {
            try {
                $turnCredentials = Media_WebRTC::getTwilioTurnCredentials();
                $turnServers = array_merge($turnServers, $turnCredentials);
            } catch (Exception $e) {
            }
        }
    
        $response = array(
            'socketServer' => $socketServer,
            'turnCredentials' => $turnServers,
            'debug' => $debug,
            'options' => array(
                'livestreaming' => $livestreaming,
                'limits' => $globalLimits
            )
        );
    
        // ro#931: the caller's room is the caller's own. With a request
        // "publisherId" this slot created (or reopened) a Media/webrtc stream
        // as any user, then edited it, joined it, related it and registered
        // Node's closeStream for it on disconnect. client.js, the only
        // caller, sends its own id.
        $publisherId = $loggedInUserId;

        // ro#931: the call is related below AS THE CALL CENTER'S PUBLISHER
        // (which skips the category's "relate" check: call centers are
        // created with public writeLevel 0) and with inheritAccess. Keep
        // that to what client.js does -- a call, into a Media/webrtc call
        // center the caller can see -- rather than any relation type into
        // any stream as its publisher. Checked before anything is written.
        $callCenterStream = null;
        if (!empty($relate["publisherId"]) && !empty($relate["streamName"]) && !empty($relate["relationType"])) {
            if ($relate["relationType"] !== 'Media/webrtc/callCenter/call') {
                throw new Q_Exception_WrongValue(array(
                    'field' => 'relate.relationType',
                    'range' => 'Media/webrtc/callCenter/call'
                ));
            }
            $callCenterStream = Streams_Stream::fetch($loggedInUserId, $relate["publisherId"], $relate["streamName"], true);
            if ($callCenterStream->type !== 'Media/webrtc') {
                throw new Q_Exception_WrongType(array(
                    'field' => 'relate.streamName',
                    'type' => 'a Media/webrtc stream'
                ));
            }
            if (!$callCenterStream->testReadLevel('content')) {
                throw new Users_Exception_NotAuthorized();
            }
        }

        $webrtcStream = null;
        if(!empty($useRelatedTo) && !empty($useRelatedTo["publisherId"]) && !empty($useRelatedTo["streamName"]) && !empty($useRelatedTo["relationType"])) {

            // ro#931: only a room of the caller's own; with a null
            // fromPublisherId this returned anyone's room related there.
            $webrtcStream = Media_WebRTC::getRoomStreamRelatedTo($useRelatedTo["publisherId"], $useRelatedTo["streamName"], $loggedInUserId, null, $useRelatedTo["relationType"], $resumeClosed);
    
            if(is_null($webrtcStream)) {
                $webrtcStream = Media_WebRTC::getOrCreateRoomStream($publisherId, $roomId, $resumeClosed, ['writeLevel' => $writeLevel]);
            }
    
        } else {
            $webrtcStream = Media_WebRTC::getOrCreateRoomStream($publisherId, $roomId, $resumeClosed, ['writeLevel' => $writeLevel]);
        }
    
        $response['stream'] = $webrtcStream;
        $response['roomId'] = $webrtcStream->name;
    
        $specificLimitsConfig = $webrtcStream->getAttribute('limits', null);
    
        if(!is_null($specificLimitsConfig)) {
            if(isset($specificLimitsConfig['video'])) {
                $response['options']['limits']['video'] = $specificLimitsConfig['video'];
            }
            if(isset($specificLimitsConfig['audio'])) {
                $response['options']['limits']['audio'] = $specificLimitsConfig['audio'];
            }
            if(isset($specificLimitsConfig['minimalTimeOfUsingSlot'])) {
                $response['options']['limits']['minimalTimeOfUsingSlot'] = $specificLimitsConfig['minimalTimeOfUsingSlot'];
            }
            if(isset($specificLimitsConfig['timeBeforeForceUserToDisconnect'])) {
                $response['options']['limits']['timeBeforeForceUserToDisconnect'] = $specificLimitsConfig['timeBeforeForceUserToDisconnect'];
            }
        }
    
        // check maxCalls
        /*if (!empty($relate["publisherId"]) && !empty($relate["streamName"]) && !empty($relate["relationType"])) {
            // if calls unavailable, throws exception
            Streams::checkAvailableRelations($publisherId, $relate["publisherId"], $relate["streamName"], $relate["relationType"], array(
                "postMessage" => false,
                "throw" => true,
                "singleRelation" => true
            ));
        }*/
    
        if ($resumeClosed !== null) {
            $response['stream']->setAttribute("resumeClosed", $resumeClosed);
        }
    
        if ($closeManually !== null) {
            $response['stream']->setAttribute("closeManually", $closeManually);
        }
    
        if ($callDescription !== null) {
            $response['stream']->content = $callDescription;
        }
    
        if($socketId !== null) {
            $response['stream']->setAttribute("socketId", $socketId);
        }
    
        $response['stream']->join();
    
        $response['stream']->setAttribute('status', 'created');

        $response['stream']->changed($publisherId);
        $response['stream']->save(false, true, true);


        if ($callCenterStream) {
            $refetchedStream = Streams_Stream::fetch($callCenterStream->fields['publisherId'],  $webrtcStream->fields["publisherId"],  $webrtcStream->fields["name"], ['refetch' => true]);

            //var_dump($callCenterStream->testAdminLevel('manage'));die;
            if($response['stream']->testWriteLevel('suggest')) {
                $response['stream']->relateTo((object) array(
                    "publisherId" => $relate["publisherId"],
                    "name" => $relate["streamName"]
                ), $relate["relationType"], $callCenterStream->fields['publisherId'], array(
                    "inheritAccess" => true, 
                    "weight" => time(),
                    "ignoreCache" => true
                ));
            } else {
                throw new Exception("You don't have permission to create a call.");
            }
        }    
    
        // close created stream when user is disconnected
        $sessionId = Q_Session::id();
    
        if ($loggedInUser) {
            Q_Utils::sendToNode(array(
                "Q/method" => "Users/addEventListener",
                "sessionId" => $sessionId,
                "socketId" => $socketId,
                "userId" => $loggedInUserId,
                "eventName" => 'disconnect',
                "handlerToExecute" => 'Media/callCenter',
                "data" => [
                    "cmd" => 'closeStream',
                    "publisherId" => $response['stream']->publisherId,
                    "streamName" => $response['stream']->name
                ],
            ));
        }
    
        return Q_Response::setSlot("room", $response);
    }

}

/**
 * ro#931: the operator slots (acceptHandler, endOrDeclineCallHandler,
 * interviewHandler, markApprovedHandler, holdHandler) wrote or deleted access
 * rows on a caller-named liveShowRoom, set attributes on, posted to and
 * closed a caller-named waitingRoom, with no check that the caller operates
 * either. This is that check: the caller manages the call center (the
 * liveShowRoom, a Media/webrtc stream; the same level
 * Media_WebRTC::admitUserToRoom() requires, which upstream's acceptHandler
 * now calls), and the waitingRoom is a call placed with it -- related to it
 * as Media/webrtc/callCenter/call, as the room slot does for client.js.
 * @method Media_callCenter_post_rooms
 * @param {string} $loggedInUserId
 * @param {array} $waitingRoom publisherId, streamName
 * @param {array} $liveShowRoom publisherId, streamName
 * @return {array} array($waitingRoomStream, $liveShowRoomStream)
 * @throws {Users_Exception_NotAuthorized}
 */
function Media_callCenter_post_rooms($loggedInUserId, $waitingRoom, $liveShowRoom)
{
    foreach (array('waitingRoom' => $waitingRoom, 'liveShowRoom' => $liveShowRoom) as $field => $room) {
        if (!is_array($room) || empty($room['publisherId']) || empty($room['streamName'])) {
            throw new Q_Exception_RequiredField(array(
                'field' => "$field.publisherId, $field.streamName"
            ));
        }
    }
    $liveShowRoomStream = Streams_Stream::fetch($loggedInUserId, $liveShowRoom['publisherId'], $liveShowRoom['streamName'], true);
    if ($liveShowRoomStream->type !== 'Media/webrtc') {
        throw new Q_Exception_WrongType(array(
            'field' => 'liveShowRoom',
            'type' => 'a Media/webrtc stream'
        ));
    }
    if (!$liveShowRoomStream->testAdminLevel('manage')) {
        throw new Users_Exception_NotAuthorized();
    }
    $waitingRoomStream = Streams_Stream::fetch($loggedInUserId, $waitingRoom['publisherId'], $waitingRoom['streamName'], true);
    if ($waitingRoomStream->type !== 'Media/webrtc') {
        throw new Q_Exception_WrongType(array(
            'field' => 'waitingRoom',
            'type' => 'a Media/webrtc stream'
        ));
    }
    $call = new Streams_RelatedTo();
    $call->toPublisherId = $liveShowRoomStream->publisherId;
    $call->toStreamName = $liveShowRoomStream->name;
    $call->type = 'Media/webrtc/callCenter/call';
    $call->fromPublisherId = $waitingRoomStream->publisherId;
    $call->fromStreamName = $waitingRoomStream->name;
    if (!$call->retrieve()) {
        throw new Users_Exception_NotAuthorized();
    }
    return array($waitingRoomStream, $liveShowRoomStream);
}