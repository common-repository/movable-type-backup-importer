<?php

/**
 * Movable Type Backup Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
class MT_Backup_Import extends WP_Importer {
	
	private $_elements = array(
		array('id' => 'author', 'label' => 'Users'),
		array('id' => 'image', 'label' => 'Images'),
		array('id' => 'file', 'label' => 'Files'),
		array('id' => 'entry', 'label' => 'Posts'),
		array('id' => 'tag', 'label' => 'Tags'),
		array('id' => 'category', 'label' => 'Categories'),
		array('id' => 'comment', 'label' => 'Comments'),
		array('id' => 'trackback', 'label' => 'Trackbacks'),
	);
	
	private $_siteurl;
	private $_path;
	private $_autosizing = false;
	private $_content_width_upscale = 500;
	private $_content_width = 550;
    private $_slug_separator = '-'; // TODO: check _
    private $_broken = array();
    private $_uploadurl = '';
	
	function MT_Backup_Import() {
		// NOOP
	}
	
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import Movable Type Backup', PLUGIN_ID).'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		$this->header();
		$directory = wp_upload_dir();
        if ($directory['error'] != '') {
            echo '<div id="message" class="error"><p><strong>' . $directory['error'] . '</strong></p></div>';
            $this->footer();
            return;
        }
		$basedir = $directory['basedir'];

        // if it's just a one file backup, we simulate multifile backup
        $backups = glob($basedir . '/import/Movable_Type-*-Backup.xml');
        if (sizeof($backups) == 1) {
            $file = $backups[0];
            rename($file, substr($file,0,-4) . '-1.xml');
        }

		$backups = glob($basedir . '/import/Movable_Type-*-Backup-1.xml');
        if (!is_dir($basedir . '/import')) {
            $result = @mkdir($basedir . '/import', 0777, true);
            if (!$result) {
                echo '<div id="message" class="error"><p>Unable to create <strong>' . $basedir . '/import</strong>. Please create it by yourself</div>';
                $this->footer();
                return;
            }
        }
		
		if (sizeof($backups) > 0) {
		?>
		<h3>Choose Backup to Import</h3>
		<div class="narrow">
		<p><?php _e('Nice! There are backups in the correct directory. Let\'s start by choosing a backup to import.') ?></p>
		<form method="post" action="<?php echo esc_attr(add_query_arg('step', 1)); ?>">
		<?php wp_nonce_field('import-mt-backup'); ?>
		<p>
		 <ul>
		  <?php foreach($backups as $index => $backup) { 
		  $filename = basename($backup);
		  ?>
		  <li>
		   <input type="checkbox" id="backup-<?php echo $index; ?>" <?php if($index == 0) { ?>checked="checked"<?php } ?> name="backup" value="<?php echo $filename ?>" />
		   <label for="backup-<?php echo $index; ?>"><i><?php echo $filename ?></i></label>
		  </li>
		  <?php } ?>
		 </ul>
		</p>
		<h3><?php _e('Which objects would you like to import?') ?></h3>
		<p>
		 <ul>
		  <?php foreach($this->_elements as $index => $element) { ?>
		  <li>
		   <input checked="checked" type="checkbox" disabled="disabled" id="element-<?php echo $index; ?>" name="elements[<?php echo $index ?>]" value="<?php echo $element['id'] ?>" />
		   <label for="element-<?php echo $index; ?>"><i><?php echo $element['label'] ?></i></label>
		  </li>
		  <?php } ?>
		 </ul>
		</p>
		<p class="submit">
		<input type="submit" class="button" value="<?php esc_attr_e('Import', PLUGIN_ID); ?>" />
		</p>
		</form>
		</div>
		
		<?php } else { ?>
		<h3>Upload Backup</h3>
		<div class="narrow">
		<p>
		 <ul>
		  <li>1. <?php _e('Upload <b>unzipped</b> backup to') ?>: <code><?php echo $basedir ?>/import</code></li>
		  <li>2. <code>chmod -R 777 <?php echo $basedir ?>/import</code></li>
		 </ul>
	    </p>
		<form method="post" action="<?php echo esc_attr(add_query_arg('step', 0)); ?>">
		<p class="submit">
		<input type="submit" class="button" value="<?php esc_attr_e('Refresh', PLUGIN_ID); ?>" />
		</p>
		</form>
		</div>
		<?php
		}
		$this->footer();
	}
		
	function dispatch() {
		$step = empty($_GET['step']) ? 0 : (int) $_GET['step'];
		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1:
				check_admin_referer('import-mt-backup');
				$result = $this->import();
				if (is_wp_error($result))
					echo $result->get_error_message();
				break;
		}
	}
	
	function import() {

        // set time limit to unlimited
		set_time_limit(0);
		
		// allow iframes and stuff
		// credits: http://wordpress.org/support/topic/bypass-sanitize_post-from-wp_insert_post
		kses_remove_filters();
		
		// disable automatic shortlink generation (because of performance reasons)
		define('DISABLE_SHORTLINK', true);
		
		// disable too much xml warnings
		if (function_exists('libxml_use_internal_errors')) {
			libxml_use_internal_errors(true);
		}
		
		// let's show the user some progress (to keep him happy, while importing)
		ob_implicit_flush(true);
		
		echo '<h2>Importing your Movable Type Backup</h2>';
		echo '<p>Your backup will be processed in chunks if available.</p>';
		$directory = wp_upload_dir();
		$basedir = $directory['basedir'] . '/import/';
		$this->_path = $basedir;
        $this->_uploadurl = $directory['baseurl'] . '/import/';
		
		// this is the path to the first backup file, but we need
		// to look for other chunks as well
		$backup = $_REQUEST['backup'];
		$basebackup = substr($backup, 0, -5);
		$chunks = glob($basedir . $basebackup . '*');
		$chunk_count = sizeof($chunks);
		
		// initialize mappings
		$mappings = array(
			'user' => array(), 
			'post' => array(),
			'assets' => array(),
			'tags' => array(),
			'categories' => array()
		);
		
		// process every single chunk file
		for ($i = 1; $i <= $chunk_count; $i++) {
			$chunk_file = $basedir . $basebackup . $i . '.xml';
			if (is_file($chunk_file)) {
				echo "<h3>" . basename($chunk_file) . "</h3>";
				$this->_import_mt_backup_file($basedir, basename($chunk_file), $mappings);
			}
		}
	}
	
	function _import_mt_backup_file($basedir, $backup, &$mappings) {
		
		// cleanup xml file before import
		$content = file_get_contents($basedir . $backup);
		
		// remove bad characters
		$content = str_replace(array("", ""), array("",""), $content);
		
		// remove unused log entries
		$content = preg_replace("%(<log.*?>.*?<\/log.*?>)%is", '', $content);
		
		// write temporary clean xml file
		file_put_contents($basedir . 'clean-' . $backup, $content);
		unset($content);
		
		// start import
		$this->_import_mt($basedir, 'clean-' . $backup, $mappings);
		
	}

	function _import_mt($path, $backup, &$mappings) {
		$backupxml = $path . $backup;
		echo '<p>Importing <code>' . $backupxml . '</code>...</p>';
		if (is_file($backupxml)) {
            echo $backupxml;
			$xml = simplexml_load_file($backupxml);
			$nodes = $xml->children();
			foreach ($nodes as $name => $node) {
				switch ($name) {
					case 'author': $this->_import_mt_user($path, $node, $mappings); break;
					case 'blog': $this->_import_mt_blog($path, $node, $mappings); break;
					case 'entry': $this->_import_mt_post($path, $node, $mappings); break;
					case 'image': $this->_import_mt_image($path, $node, $mappings); break;
					case 'file': $this->_import_mt_file($path, $node, $mappings); break;
					case 'tag': $this->_import_mt_tag($path, $node, $mappings); break;
					case 'placement': $this->_import_mt_placement($path, $node, $mappings); break;
					case 'category': $this->_import_mt_category($path, $node, $mappings); break;
					case 'objecttag': $this->_import_mt_objecttag($path, $node, $mappings); break;
					case 'objectasset': $this->_import_mt_objectasset($path, $node, $mappings); break;
					case 'comment': $this->_import_mt_comment($path, $node, $mappings); break;
					// case 'trackback': $this->_import_mt_trackback($path, $node, $mappings); break;
				}
			}
		}
	}
	
	function _import_mt_blog($path, $blog, &$mappings) {
		$this->_siteurl = $blog->attributes()->site_url;
	}

	function _import_mt_user($path, $user, &$mappings) {

		$id = (string) $user->attributes()->id;
		$email = (string) $user->attributes()->email;
		$username = (string) $user->attributes()->name;
		$nickname = (string) $user->attributes()->nickname;
		$status = (string) $user->attributes()->status; // 1 = Active, 2 = Inactive
		$password = (string) $user->attributes()->password;
		$password = strlen($password) > 0 ? $password : wp_generate_password();
		$created = (string) $user->attributes()->created_on;
		$role = $status == 1 ? 'editor' : 'subscriber';

		$data = array(
			'user_pass' => $password,
			'user_login' => $username,
			'user_email' => strtolower($email),
			'display_name' => $nickname,
			'user_registered' => date('Y-m-d H:i:s', strtotime($created)),
			'role' => $role
		);
		
		// Users with "ldaprename" prefix are not handled correctly
		if (substr($username,0,10) == 'ldaprename') {
			$user_id = username_exists(substr($username,10));
			if ($user_id !== null) {
				// user already exists, perfect, so nothing to do here, except updating our mapping
				// array for later use.
				$mappings['user'][(string)$id] = $user_id;
				return;
			} else {
                // If the username is just "ldaprename", we kick it
                if (strlen($username) > 10) {
				    $data['user_login'] = strtolower(substr($username,10));
                } else {
                    return;
                }
			}
		}
		
		// if there is still no password, set a random one.
		// ldap user don't have passwords set, so we generate a random one to not
		// allow them to enter if something goes wrong with the ldap connection.
		if (trim($data['user_pass']) == '' || trim($data['user_pass']) == '(none)') {
			$data['user_pass'] = wp_generate_password();
		}

        if (function_exists('mt_import_user_data')) {
            mt_import_user_data($data);
        }

		// check if user already exists
		$user_id = username_exists($username);
		if (!$user_id) {
			$user_id = wp_insert_user($data);
		}
		
		// add author role to user for this blog
		global $current_blog;
		if (function_exists('add_user_to_blog')) {
			add_user_to_blog($current_blog->blog_id, $user_id, 'editor');
		}
		
		// add mapping
		$mappings['user'][(string)$id] = $user_id;
		
	}

	function _import_mt_asset($path, $asset, &$mappings) {

		$id = (string) $asset->attributes()->id;
		$filename = (string) $asset->attributes()->file_name;
		$mimetype = (string) $asset->attributes()->mime_type;
		$label = (string) $asset->attributes()->label;
		$parent = (string) $asset->attributes()->parent;
		$entry_id = (string) $asset->attributes()->entry_id;
		$title = strlen($label) > 0 ? $label : $filename;
		$author = (string) $asset->attributes()->created_by;

		// Skip, if it's a thumbnail
		if ($parent > 0) {
			return;
		}

		$attachment = array(
		     'post_mime_type' => $mimetype,
		     'post_title' => preg_replace('/\.[^.]+$/', '', $title),
		     'post_content' => '',
		     'post_status' => 'inherit',
			 'post_author' => isset($mappings['user'][$author]) ? $mappings['user'][$author] : null,
		);

		$filepath = $path . '/' . $id . '-' . $filename;
		$attach_id = wp_insert_attachment($attachment, $filepath, null);
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		$attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
		wp_update_attachment_metadata($attach_id, $attach_data);
		$mappings['assets'][$id] = $attach_id;
	
	}
	
	function _import_mt_image($path, $post, &$mappings) {
		$this->_import_mt_asset($path, $post, $mappings);
	}
	
	function _import_mt_file($path, $post, &$mappings) {
		$this->_import_mt_asset($path, $post, $mappings);
	}

	function _import_mt_post($path, $post, &$mappings) {

		$id = $post->attributes()->id;
		$allow_comments = $post->attributes()->allow_comments;
		$allow_pings = $post->attributes()->allow_pings;
		$atom_id = $post->attributes()->atom_id;
		$author_id = (string) $post->attributes()->author_id;
		$authored_on = date('Y-m-d H:i:s', strtotime($post->attributes()->authored_on));
		$basename = $post->attributes()->basename;
		$comment_count = $post->attributes()->comment_count;
		$class = $post->attributes()->class;
		$created_by = $post->attributes()->created_by;
		$created_on = date('Y-m-d H:i:s', strtotime($post->attributes()->created_on));
		$authored_on = date('Y-m-d H:i:s', strtotime($post->attributes()->authored_on));
		$modified_by = $post->attributes()->modified_by;
		$modified_on = date('Y-m-d H:i:s', strtotime($post->attributes()->modified_on));
		$ping_count = $post->attributes()->ping_count;
		$status = $post->attributes()->status;
		$title = $post->attributes()->title;
		$weeknumber = $post->attributes()->weeknumber;
		$content = (string) $post->text;
		$search = array();
		$replace = array();
		$preg_search = array();
		$preg_replace = array();

        // support for extended content
        if ('' != trim($post->text_more)) {
            $extended = (string) $post->text_more;
            $content = $content . "\n<!--more-->\n" . $extended;
        }

		if (strlen($content) > 0) {
			// replace image tags
			$pattern = '/<img.*?src="(.*?)"(.*?)>/is';
			$content = preg_replace_callback($pattern, array('self', '_replace_mt_image'), $content);
			
			// replace links
			$pattern = '/<a.*?href="(.*?)".*?>(.*?)<\/a>/is';
			$content = preg_replace_callback($pattern, array('self', '_replace_mt_link'), $content);
		}

		$post = new StdClass;
		$post->post_content = $content;
		$post->post_author = isset($mappings['user'][$author_id]) ? $mappings['user'][$author_id] : null;
		$post->post_title = $title;
		$post->post_name = str_replace('_', $this->_slug_separator, $basename);
		$post->post_status = $status == 2 ? 'publish' : null; // TODO
		$post->post_modified = $modified_on;
		$post->post_modified_gmt = get_gmt_from_date($modified_on);
		$post->post_date = $authored_on;
		$post->post_date_gmt = get_gmt_from_date($authored_on);
		$post->ping_status = $allow_pings == 1 ? true : false;
		$post->comment_status = $allow_comments == 1 ? 'open' : 'closed';
		
		// Tell Wordpress that the content is already filtered to allow embed, iframe, etc.
		// Credits to: http://pp19dd.com/2010/06/unfiltered-wp_insert_post/
		$post->filter = true;
        $post_id = $this->_save_post($post);
        $mappings['posts'][(string)$id] = $post_id;

	}

	function _import_mt_tag($path, $tag, &$mappings) {

		$id = $tag->attributes()->id;
		$n8d_id = $tag->attributes()->n8d_id;
		$name = $tag->attributes()->name;
		$mappings['tags'][(string)$id] = (string)$name;

	}

	function _import_mt_objecttag($path, $tag, &$mappings) {

		$id = $tag->attributes()->id;
		$object_datasource = $tag->attributes()->object_datasource;
		$object_id = (string) $tag->attributes()->object_id;
		$tag_id = (string) $tag->attributes()->tag_id;

		if ($object_datasource == 'entry') {
			wp_add_post_tags($mappings['posts'][$object_id], $mappings['tags'][$tag_id]);
		}

	}

	function _import_mt_category($path, $category, &$mappings) {

		$id = $category->attributes()->id;
		$allow_pings = $category->attributes()->allow_pings;
		$author_id = $category->attributes()->author_id;
		$basename = $category->attributes()->basename;
		$created_by = $category->attributes()->created_by;
		$created_on = $category->attributes()->created_on;
		$label = $category->attributes()->label;
		$modified_on = $category->attributes()->modified_on;
		$parent = $category->attributes()->parent;

		$data = array(
		  'cat_name' => $label,
		  'category_nicename' => $basename
		);
		$category = get_category_by_slug($basename);
		if (!$category) {
            $wp_error = array();
			$cat_id = wp_insert_category($data, $wp_error);
		} else {
			$cat_id = $category->cat_ID;
		}
		$mappings['categories'][(string)$id] = $cat_id;

	}

	function _import_mt_placement($path, $placement, &$mappings) {

		$id = $placement->attributes()->id;
		$category_id = (string) $placement->attributes()->category_id;
		$post_id = (string) $placement->attributes()->entry_id;
		$is_primary = $placement->attributes()->is_primary; // 1 = true, 0 = false

		wp_set_post_categories($mappings['posts'][$post_id], array($mappings['categories'][$category_id]));

	}

	function _import_mt_objectasset($path, $objectasset, &$mappings) {

		$id = (string) $objectasset->attributes()->id;
		$asset_id = (string) $objectasset->attributes()->asset_id;
		$embedded = (string) $objectasset->attributes()->embedded;
		$object_ds = (string) $objectasset->attributes()->object_ds;
		$object_id = (string) $objectasset->attributes()->object_id;
		if ($object_ds == 'entry') {
			wp_update_post(array('ID' => $mappings['assets'][$asset_id], 'post_parent' => $mappings['posts'][$object_id]));
		}

	}

	function _import_mt_comment($path, $comment, &$mappings) {

		$id = (string) $comment->attributes()->id;
		$author = (string) $comment->attributes()->author;
		$created_on = date('Y-m-d H:i:s', strtotime($comment->attributes()->created_on));
		$entry_id = (string) $comment->attributes()->entry_id;
		$email = (string) $comment->attributes()->email;
		$url = (string) $comment->attributes()->url;
		$ip = (string) $comment->attributes()->ip;
		$junk_status = (string) $comment->attributes()->junk_status; // 1 = OK
		$last_moved_on = date('Y-m-d H:i:s', strtotime($comment->attributes()->last_moved_on));
		$visible = (string) $comment->attributes()->visible;
		$text = (string) $comment->text;

		if ($junk_status == 1) {
			$comment = array();
			$comment['comment_author'] = $author;
			$comment['comment_author_email'] = $email;
			$comment['comment_date'] = $created_on;
			$comment['comment_status'] = 'open';
			$comment['comment_author_IP'] = $ip;
			$comment['comment_author_url'] = $url;
			$comment['comment_content'] = $text;

			$comment = add_magic_quotes($comment);
			if (!comment_exists($comment['comment_author'], $comment['comment_date'])) {
				$comment['comment_post_ID'] = $mappings['posts'][$entry_id];
				$comment = wp_filter_comment($comment);
				wp_insert_comment($comment);
			}
		}

	}

	function _import_mt_trackback($path, $trackback, &$mappings) {

		$id = (string) $trackback->attributes()->id;
		$created_by = (string) $trackback->attributes()->created_by;
		$created_on = date('Y-m-d H:i:s', strtotime($trackback->attributes()->created_on));
		$entry_id = (string) $trackback->attributes()->entry_id;
		$title = (string) $trackback->attributes()->email;
		$url = (string) $trackback->attributes()->url;
		$modified_on = date('Y-m-d H:i:s', strtotime($trackback->attributes()->modified_on));
		$is_disabled = (string) $trackback->attributes()->is_disabled;
		$text = (string) $trackback->description;

		$ping = array();
		$ping['comment_type'] = 'trackback';
		$ping['comment_author'] = $mappings['users'][$created_by];
		$ping['comment_date'] = $created_on;
		$ping['ping_status'] = 'open';
		$ping['comment_author_url'] = $url;
		$ping['comment_content'] = "<strong>{$ping['title']}</strong>\n\n{$ping['comment_content']}";

		$ping = add_magic_quotes($ping);
		if (!comment_exists($ping['comment_author'], $ping['comment_date'])) {
			$ping['comment_post_ID'] = $mappings['posts'][$entry_id];
			$ping = wp_filter_comment($ping);
			wp_insert_comment($ping);
		}

	}

    function _download_broken_url($url) {
		$filename = 'i-' . md5($url) . '-' . urldecode(basename($url));
		if (!is_file($this->_uploadurl . $filename)) {
			echo "<li>Downloading broken resource: " . $url . "</li>";
			$content = @file_get_contents($url);
			if ($content !== FALSE) {
				file_put_contents($this->_path . '/' . $filename, $content);
				return $this->_uploadurl . $filename;
			}
		} else {
			return $this->_uploadurl . $filename;
		}
		return null;
	}

    function _replace_mt_image($image) {
		$siteurl_length = strlen($this->_siteurl);
		if (substr($image[1], 0, $siteurl_length) == $this->_siteurl) {
			$filename = urldecode(basename($image[1]));
			$originals = glob($this->_path . '/*-' . $filename);

			// try to get original width and define new sizing
			preg_match_all('/width="(\d+)" height="(\d+)"/is', $image[2], $matches);
			$sizing = '';
			if (sizeof($matches[1]) > 1) {
				$width = $matches[1][0];
				$height = $matches[1][1];
				$sizing = ' width="' . $width . '" height="' . $height . '"';
			}

			if (sizeof($originals) > 0) {
				$new = basename($originals[0]);
				if ($this->_autosizing) {
					list($width, $height, $type, $attr) = getimagesize($originals[0]);
					if ($width >= $this->_content_width_upscale) {
						$height = round($this->_content_width/($width/$height));
						$sizing = ' width="' . $this->_content_width . '" height="' . $height . '"';
					}
				}
				$replace = $this->_uploadurl . $new;
				return '<img src="' . $replace. '" alt="' . $new . '"' . $sizing . ' />';
			} else {
				$url = $this->_download_broken_url($image[1]);
				if ($url === null) {
					echo "<span style=\"color: red;\">BROKEN</span>: " . $image[1] . "<br />";
                    $this->_broken[] = array('type' => 'image', 'url' => $image[1]);
				} else {
					return '<img src="' . $url. '" alt="' . basename($url) . '"' . $sizing . ' />';
				}
			}
		}
		return $image[0];
	}

	/**
	 * Replaces URLs in Links with the new one. If the link points to an HTML popup with an
	 * image (guessed), it will be removed. If the link points to image of the current domain,
	 * but it doesn't exist, the script tries to download the image and returns the correct
	 * link.
     *
     * @param $link Link URL
     * @return string corrected link markup
	 */
	function _replace_mt_link($link) {
		$siteurl_length = strlen($this->_siteurl);
		if (substr($link[1], 0, $siteurl_length) == $this->_siteurl) {
			$filename = urldecode(basename($link[1]));
			$originals = glob($this->_path . '/*-' . $filename);
			if (sizeof($originals) > 0) {
				$new = basename($originals[0]);
				$replace = $this->_uploadurl . $new;
				if (strpos($link[1], 'assets_c') > 0 && substr($new,-5) == '.html') {
					return $link[2];
				} else {
					return '<a href="' . $replace . '">' . $link[2] . '</a>';
				}
			} else {
				$replace = str_replace($this->_siteurl, get_bloginfo('url') . '/', $link[1]);
				if (substr($replace, -5) == '.html' || strpos(basename($link[1]), '.') === false) {
					return '<a href="' . $replace . '">' . $link[2] . '</a>';
				}
				$url = $this->_download_broken_url($link[1]);
				if ($url === null) {
					echo "<span style=\"color: red;\">BROKEN</span>: " . $link[1] . "<br />";
                    $this->_broken[] = array('type' => 'link', 'url' => $link[1]);
				} else {
					return '<a href="' . $url . '">' . $link[2] . '</a>';
				}
			}
		}
		return $link[0];

	}

	function _save_post(&$post) {
		$post = get_object_vars($post);
		$post = add_magic_quotes($post);
		$post = (object) $post;

		if ( $post_id = post_exists($post->post_title, '', $post->post_date) ) {
			echo '<li>';
			printf(__('Post <em>%s</em> already exists.', PLUGIN_ID), stripslashes($post->post_title));
		} else {
			echo '<li>';
			printf(__('Importing post <em>%s</em>...', PLUGIN_ID), stripslashes($post->post_title));
			$post_id = wp_insert_post($post);
			if (is_wp_error($post_id)) {
				echo "ERROR";
				return $post_id;
			}
		}
		return $post_id;
	}
	
	function _get_blog_list() {
		global $wpdb;
		$blogs = $wpdb->get_results($wpdb->prepare("
			SELECT blog_id, domain, path 
			FROM $wpdb->blogs 
			WHERE 
				public = '1' AND 
				archived = '0' AND 
				mature = '0' AND 
				spam = '0' AND 
				deleted = '0' 
			ORDER BY registered DESC"
			), ARRAY_A
		);
	}
	
}

?>