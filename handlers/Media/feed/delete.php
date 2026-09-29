<?php

/**
 * @module Media
 */

/**
 * Close feed stream, as the logged-in user.
 * The user needs the "close" write level on the stream, which must be a Media/feed.
 * @class HTTP Media feed
 * @method delete
 * @param {array} $_REQUEST
 * @param {string} [$_REQUEST.publisherId] Required. Feed stream publisher id.
 * @param {string} [$_REQUEST.streamName] Required. Feed stream name.
 */
function Media_feed_delete ($params) {
	$user = Users::loggedInUser(true);
	// Q/delete checks the nonce without throwing, and only AJAX requests are
	// refused for it later, so check it here as Streams/access PUT does.
	Q_Valid::nonce(true);
	$r = array_merge($_REQUEST, $params);
	$required = array('streamName', 'publisherId');
	Q_Valid::requireFields($required, $r, true);

	// Act as the logged-in user only. This used to take a "userId" from the
	// request and then close the stream as its publisher, which skips the
	// access check in Streams::close(), so anyone could close any stream.
	$stream = Streams_Stream::fetch($user->id, $r['publisherId'], $r['streamName'], true);
	if ($stream->type !== 'Media/feed') {
		throw new Q_Exception_WrongType(array(
			'field' => 'streamName',
			'type' => 'a Media/feed stream'
		));
	}
	if (!$stream->testWriteLevel('close')) {
		throw new Users_Exception_NotAuthorized();
	}

	// close stream (Streams::close() checks the access again)
	$stream->close($user->id);
}
