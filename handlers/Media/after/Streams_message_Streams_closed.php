<?php
function Media_after_Streams_message_Streams_closed ($params) {
	$stream = $params['stream'];

	if ($stream->type == "Media/feed") {
		// send message to feeds categories about feed closed
		$participatedCategories = Streams_Participant::select()->where(array(
			"streamName" => "Media/feeds",
			"userId" => $stream->publisherId,
			"state" => "participating"
		))->fetchDbRows();
		foreach ($participatedCategories as $participatedCategory) {
			Streams_Message::post($stream->publisherId, $participatedCategory->publisherId, $participatedCategory->streamName, array(
				'type' => 'Media/feed/closed',
				'instructions' => array("publisherId" => $stream->publisherId, "streamName" => $stream->name)
			), true);
		}
	}

	// Remove this stream's own uploaded files.
	//
	// This used to delete, recursively, whatever directory the stream's
	// "Q.file.url" attribute named ({{baseUrl}} mapped to APP_WEB_DIR, or any
	// absolute path), for a closed stream of ANY type. A client may write that
	// attribute through Streams/stream PUT on a stream it can edit (a
	// Media/clip it published), so any logged-in user could have the server
	// wipe any directory it can write. Now: only Media's own types, and only
	// that stream's own directory under the uploads root, compared after
	// realpath(). The attribute only says whether there were uploads at all.
	if (strpos($stream->type, 'Media/') !== 0) {
		return;
	}
	$url = $stream->getAttribute("Q.file.url", "");
	if (!$url || !is_string($url) || strpos($stream->name, '..') !== false) {
		return;
	}
	$root = realpath(APP_WEB_DIR.DS.'Q'.DS.'uploads'.DS.'Streams');
	if (!$root) {
		return;
	}
	$own = realpath($root.DS.Q_Utils::splitId($stream->publisherId, 3, DS).DS
		.str_replace('/', DS, $stream->name));
	if (!$own || strpos($own, $root.DS) !== 0) {
		return; // nothing uploaded, or not under the uploads root
	}
	$dir = preg_replace("/{{baseUrl}}/", APP_WEB_DIR, $url);
	$dir = preg_replace("#".preg_quote($stream->name, '#').".+#", $stream->name, $dir);
	if (realpath($dir) !== $own) {
		return; // the attribute names some other directory: leave it alone
	}
	$dir = $own;

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($files as $fileinfo) {
		// the entry itself, never what a symlink in there points to
		if ($fileinfo->isLink() || !$fileinfo->isDir()) {
			unlink($fileinfo->getPathname());
		} else {
			rmdir($fileinfo->getPathname());
		}
	}
	rmdir($dir);
}
