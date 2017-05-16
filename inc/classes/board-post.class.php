<?php
/*
 * This file is part of kusaba.
 *
 * kusaba is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * kusaba is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * kusaba; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */
/**
 * Board and Post classes
 *
 * @package kusaba
 */
/**
 * Board class
 *
 * Contains all board configurations.  This class handles all board page
 * rendering, using the templates
 *
 * @package kusaba
 *
 * TODO: replace repetitive code blocks with functions.
 */

class Board {
	/* Declare the public variables */
	/**
	 * Array to hold the boards settings
	 */
	var $board = array();
	/**
	 * Archive directory, set when archiving is enabled
	 *
	 * @var string Archive directory
	 */
	var $archive_dir;
	/**
	 * Dwoo class
	 *
	 * @var class Dwoo
	 */
	var $dwoo;
	/**
	 * Dwoo data class
	 *
	 * @var class Dwoo
	 */
	var $dwoo_data;
	/**
	 * Load balancer class
	 *
	 * @var class Load balancer
	 */
	var $loadbalancer;

	/**
	 * Initialization function for the Board class, which is called when a new
	 * instance of this class is created. Takes a board directory as an
	 * argument
	 *
	 * @param string $board Board name/directory
	 * @param boolean $extra grab additional data for page generation purposes. Only false if all that's needed is the board info.
	 * @return class
	 */
	function __construct($board, $extra = true) {
		global $tc_db, $CURRENTLOCALE;

		// If the instance was created with the board argument present, get all of the board info and configuration values and save it inside of the class
		if ($board!='') {
			$query = "SELECT * FROM `".KU_DBPREFIX."boards` WHERE `name` = ".$tc_db->qstr($board)." LIMIT 1";
			$results = $tc_db->GetAll($query);
			foreach ($results[0] as $key=>$line) {
				if (!is_numeric($key)) {
					$this->board[$key] = $line;
				}
			}
			// Type
			$types = array('img', 'txt', 'oek', 'upl');
			$this->board['text_readable'] = $types[$this->board['type']];
			if ($extra) {
				// Boardlist
				$this->board['boardlist'] = $this->DisplayBoardList();

				// Get the unique posts for this board
				$this->board['uniqueposts']   = $tc_db->GetOne("SELECT COUNT(DISTINCT `ipmd5`) FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $this->board['id']. " AND  `IS_DELETED` = 0");
			
				if($this->board['type'] != 1) {
					$this->board['filetypes_allowed'] = $tc_db->GetAll("SELECT ".KU_DBPREFIX."filetypes.filetype FROM ".KU_DBPREFIX."boards, ".KU_DBPREFIX."filetypes, ".KU_DBPREFIX."board_filetypes WHERE ".KU_DBPREFIX."boards.id = " . $this->board['id'] . " AND ".KU_DBPREFIX."board_filetypes.boardid = " . $this->board['id'] . " AND ".KU_DBPREFIX."board_filetypes.typeid = ".KU_DBPREFIX."filetypes.id ORDER BY ".KU_DBPREFIX."filetypes.filetype ASC;");
				}
				
				if ($this->board['locale'] && $this->board['locale'] != KU_LOCALE) {
					changeLocale($this->board['locale']);
				}
			}
			$this->board['loadbalanceurl_formatted'] = ($this->board['loadbalanceurl'] != '') ? substr($this->board['loadbalanceurl'], 0, strrpos($this->board['loadbalanceurl'], '/')) : '';

			if ($this->board['loadbalanceurl'] != '' && $this->board['loadbalancepassword'] != '') {
				require_once KU_ROOTDIR . 'inc/classes/loadbalancer.class.php';
				$this->loadbalancer = new Load_Balancer;

				$this->loadbalancer->url = $this->board['loadbalanceurl'];
				$this->loadbalancer->password = $this->board['loadbalancepassword'];
			}
		}
	}

	function __destruct() {
		changeLocale(KU_LOCALE);
	}
	
	/**
	 * Regenerate all board and thread pages
	 */
	function RegenerateAll() {
		$this->RegeneratePages();
		$this->RegenerateThreads();
	}

	/**
	 * Regenerate pages
	 */
	function RegeneratePages($startpage=-1, $direction="all") {
    global $tc_db, $CURRENTLOCALE;
    $tc_db->SetFetchMode(ADODB_FETCH_ASSOC); 
    $this->InitializeDwoo();
    $do_all = ($startpage==-1 || $direction=="all");

    $ftypes = $tc_db->GetAll("SELECT `filetype` FROM `" . KU_DBPREFIX . "embeds`");
    foreach ($ftypes as $line) {
      $this->board['filetypes'][] .= $line['filetype'];
    }
    $this->dwoo_data->assign('filetypes', $this->board['filetypes']);
    
    $maxpages = $this->board['maxpages'];

    $threads = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $this->board['id'] . " AND `parentid` = 0 AND `IS_DELETED` = 0 ORDER BY `stickied` DESC, `bumped` DESC");
    $total_threads = count($threads);

    $pages = array();

    // split threads into pages →
    for ($i=0; $i < $total_threads; $i++) { 
      $current_page = floor($i / KU_THREADS);

      // fill thread stats →
      $threads[$i]['page'] = $current_page;
      $stats = $tc_db->GetAll("SELECT 
        COUNT(*) `reply_count`, 
        MAX(`timestamp`) `replied`, 
        MAX(`id`) `last_reply`,
        SUM(CASE WHEN `file_md5` != '' THEN 1 ELSE 0 END) `images` 
      FROM `".KU_DBPREFIX."posts` 
      WHERE `boardid` = '". $this->board['id'] ." '
        AND `IS_DELETED` = 0 
        AND `parentid` = '". $threads[$i]['id'] ."'");
      $stats = $stats[0];
      $threads[$i]['reply_count'] = $stats['reply_count'];
      $threads[$i]['replied'] = $stats['replied'];
      $threads[$i]['last_reply'] = $stats['last_reply'];
      $threads[$i]['images'] = $stats['images'];
      if ($threads[$i]['file_md5'] != '') {
        $threads[$i]['images']++;
      }
      // ← fill thread stats

      $pages[$current_page] []= $threads[$i];
    } // ← split thread into pages

    // rebuild pages needing to be rebuilt →
    $page = 0; 
    $starter_page_passed = false;
    $totalpages = count($pages);
    if (!$totalpages) {
      $pages []= array();
    }
    $this->dwoo_data->assign('numpages', $totalpages-1);
    
    foreach ($pages as $pagethreads) {
      $is_starter_page = ($page == $startpage);
      if ($is_starter_page) {
        $starter_page_passed = true;
      }
      if ($do_all || $is_starter_page || ($direction=="down" && $starter_page_passed) || ($direction=="up" && !$starter_page_passed)) {
        // page must be rebuilt
        $executiontime_start_page = microtime_float();
        $newposts = array();
        $this->dwoo_data->assign('thispage', $page);
        foreach ($pagethreads as $thread) {

          // If the thread is on the page set to mark, && hasn't been marked yet, mark it →
          if ($thread['deleted_timestamp'] == 0 && $this->board['markpage'] > 0 && $page >= $this->board['markpage']) {
            $tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `deleted_timestamp` = '" . (time() + 7200) . "' WHERE `boardid` = " . $tc_db->qstr($this->board['id'])." AND `id` = '" . $thread['id'] . "'");
            clearPostCache($thread['id'], $this->board['name']);
            $this->RegenerateThreads($thread['id']);
            $this->dwoo_data->assign('replythread', 0);
          } // ← If the thread is on the page set to mark, && hasn't been marked yet, mark it

          // If the thread is back on safe page, unmark it →
          if ($this->board['markpage'] == 0 || $thread['deleted_timestamp'] != 0 && $page < $this->board['markpage']) {
            $tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `deleted_timestamp` = '0' WHERE `boardid` = " . $tc_db->qstr($this->board['id'])." AND `id` = '" . $thread['id'] . "'");
            $thread['deleted_timestamp'] = 0;
          } // ← If the thread is back on safe page, unmark it
          
          // Get last posts to render →
          $posts = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "posts` 
            WHERE `boardid` = " . $this->board['id']." 
              AND `parentid` = ".$thread['id']." ". 
              "AND `IS_DELETED` = 0
            ORDER BY `id` DESC 
            LIMIT ".(($thread['stickied'] == 1) ? (KU_REPLIESSTICKY) : (KU_REPLIES)));

          $images_shown = 0;
          foreach ($posts as &$post) {
            if ($post['file_md5'] != '') {
              $images_shown++;
            }
            $post = $this->BuildPost($post, true);
          }
          $posts = array_reverse($posts);
          // ← Get last posts to render

          // Calculate omitted posts and images →
          $omitted_replies = $thread['reply_count'] - count($posts);
          if ($omitted_replies < 0) $omitted_replies = 0;
          
          if ($thread['file_md5'] != '') {
            $images_shown++;
          }
          $omitted_images = $thread['images'] - $images_shown;
          if ($omitted_images < 0) $omitted_images = 0;
          // ← Calculate omitted posts and images

          $thread = $this->BuildPost($thread, true);

          $thread['replies'] = $omitted_replies;
          $thread['images'] = $omitted_images;

          $this->dwoo_data->assign('debug_timestring', $timestr);

          array_unshift($posts, $thread);
          $newposts[] = $posts;
        }
        if ($this->board['type'] == 0 && !isset($embeds)) {
          $embeds = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "embeds`");
          $this->dwoo_data->assign('embeds', $embeds);
        }
        if (!isset($header)){
          $header = $this->PageHeader();
          $header = str_replace("<!sm_threadid>", 0, $header);
        }
        if (!isset($postbox)) {
          $postbox = $this->Postbox();
          $postbox = str_replace("<!sm_threadid>", 0, $postbox);
        }
        $this->dwoo_data->assign('posts', $newposts);
        $this->dwoo_data->assign('file_path', getCLBoardPath($this->board['name'], $this->board['loadbalanceurl_formatted'], ''));

        $content = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_board_page.tpl', $this->dwoo_data);
        $footer = $this->Footer(false, (microtime_float() - $executiontime_start_page), (($this->board['type'] == 1) ? (true) : (false)));
        $content = $header.$postbox.$content.$footer;

        $content = str_replace("\t", '',$content);
        $content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

        $filename = KU_BOARDSDIR.$this->board['name'].'/'.($page==0 ? KU_FIRSTPAGE : '/'.$page.'.html');
        $this->PrintPage($filename, $content, $this->board['name']);
      }
      $page++;
    } // ← rebuild pages needing to be rebuilt

    // build catalog →
    if ($this->board['enablecatalog'] == 1 && ($this->board['type'] == 0 || $this->board['type'] == 2)) {
      $executiontime_start_catalog = microtime_float();
      $catalog_head = $this->PageHeader().
      '<script src="'.KU_BOARDSFOLDER.'lib/javascript/lodash.min.js"></script>'.
      '<script> is_catalog=true; </script>'.
      '&#91;<a href="' . KU_BOARDSFOLDER . $this->board['name'] . '/">'._gettext('Return').'</a>&#93; '.
      '&#91;<a href="#" id="refresh_catalog">'._gettext('Refresh').'</a>&#93;'.
      '<div class="catalogmode">'.
      _gettext('Catalog Mode').'<div id="catalog-controls"></div></div>' . "\n".
      '<div id="catalog-contents"></div>';

      $catalog_nojs = '<table border="1" align="center">' . "\n" . '<tr>' . "\n";

      // Fields to go into JSON file
      $json_fields = array('id' , 'subject' , 'message', 'file' , 'file_type', 'image_w', 'image_h', 'thumb_w', 'thumb_h', 'timestamp', 'stickied', 'locked', 'bumped', 'name', 'tripcode', 'posterauthority', 'deleted_timestamp', 'page', 'reply_count', 'replied', 'last_reply', 'images');
      $catalog_json = array();

      if ($total_threads > 0) {
        $celnum = 0;
        $trbreak = 0;
        $row = 1;
        // Calculate the number of rows we will actually output
        $maxrows = max(1, (($total_threads - ($total_threads % 12)) / 12));
        foreach ($threads as $thread) {
          // populate JSON object along the way →
          unset($thread_json);
          foreach ($json_fields as $field) {
            $thread_json[$field] = $thread[$field];
          }
          $catalog_json []= $thread_json;
          // ← populate JSON object along the way

          $celnum++;
          $trbreak++;
          if ($trbreak == 13 && $celnum != $total_threads) {
            $catalog_nojs .= '</tr>' . "\n" . '<tr>' . "\n";
            $row++;
            $trbreak = 1;
          }
          if ($row <= $maxrows) {
            $catalog_nojs .= '<td valign="middle">' . "\n" .
            '<a class="catalog-entry" href="' . KU_BOARDSFOLDER . $this->board['name'] . '/res/' . $thread['id'] . '.html"';
            if ($thread['subject'] != '') {
              $catalog_nojs .= ' title="' . $thread['subject'] . '"';
            }
            $catalog_nojs .= '>';
            if ($thread['file'] != '' && $thread['file'] != 'removed') {
              if($thread['file_type'] == 'webm') $thread['file_type'] = 'jpg';
              if ($thread['file_type'] == 'jpg' || $thread['file_type'] == 'png' || $thread['file_type'] == 'gif') {
                $file_path = getCLBoardPath($this->board['name'], $this->board['loadbalanceurl_formatted'], $this->archive_dir);
                $catalog_nojs .= '<img src="' . $file_path . '/thumb/' . $thread['file'] . 'c.' . $thread['file_type'] . '" alt="' . $thread['id'] . '" border="0" />';
              } else {
                $catalog_nojs .= _gettext('File');
              }
            } elseif ($thread['file'] == 'removed') {
              $catalog_nojs .= 'Rem.';
            } else {
              $catalog_nojs .= _gettext('None');
            }
            $catalog_nojs .= '</a><br />' . "\n" . '<small>' . $thread['reply_count'] . '</small>' . "\n" . '</td>' . "\n";
          }
        }
      }
      else {
        $catalog_nojs .= '<td>' . "\n" . _gettext('No threads.') . "\n" . '</td>' . "\n";
      }
      $catalog_nojs .= '</tr>' . "\n" . '</table><br /><hr />';
      $catalog_foot = $this->Footer(false, (microtime_float()-$executiontime_start_catalog));
      $catalog_html = $catalog_head . '<noscript>'.$catalog_nojs.'</noscript>' . $catalog_foot;
      $this->PrintPage(KU_BOARDSDIR . $this->board['name'] . '/catalog.html', $catalog_html, $this->board['name']);
      $this->PrintPage(KU_BOARDSDIR . $this->board['name'] . '/catalog.json', json_encode($catalog_json), $this->board['name']);
    } // ← build catalog

    // Delete old pages  →
    $dir = KU_BOARDSDIR.$this->board['name'];
    $files = glob ("$dir/*.html");
    if (is_array($files)) {
      foreach ($files as $htmlfile) {
        if (preg_match("/[0-9+].html/", $htmlfile)) {
          if (substr(basename($htmlfile), 0, strpos(basename($htmlfile), '.html')) > $totalpages) {
            unlink($htmlfile);
          }
        }
      }
    } // ← Delete old pages
  }

	/**
	 * Regenerate each thread's corresponding html file, starting with the most recently bumped
	 */
	function RegenerateThreads($id = 0) {
		global $tc_db, $CURRENTLOCALE;
		require_once(KU_ROOTDIR."lib/dwoo.php");
		if (!isset($this->dwoo)) { $this->dwoo = New Dwoo; $this->dwoo_data = new Dwoo_Data(); $this->InitializeDwoo(); }
		$embeds = Array();
		$numimages = 0;
		if ($this->board['type'] != 1 && !$embeds) {
				$embeds = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "embeds`");
				$this->dwoo_data->assign('embeds', $embeds);
				foreach ($embeds as $embed) {
					$this->board['filetypes'][] .= $embed['filetype'];
				}
				$this->dwoo_data->assign('filetypes', $this->board['filetypes']);
		}
		if ($id == 0) {
			// Build every thread
			$header = $this->PageHeader(1);
			if ($this->board['type'] != 2){
				$postbox = $this->Postbox(1);
			}
			$threads = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $this->board['id'] . " AND `parentid` = 0 AND `IS_DELETED` = 0 ORDER BY `id` DESC");

			if (count($threads) > 0) {
				foreach($threads as $thread) {
					$numimages = 0;
					$executiontime_start_thread = microtime_float();
					$posts = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $this->board['id'] . " AND (`id` = " . $thread['id'] . " OR `parentid` = " . $thread['id'] . ") " . (($this->board['type'] != 1) ? ("AND `IS_DELETED` = 0") : ("")) . " ORDER BY `id` ASC");
					if ($this->board['type'] != 1 || ((isset($posts[0]['IS_DELETED']) && $posts[0]['IS_DELETED'] == 0) || (isset($posts[0]['is_deleted']) && $posts[0]['is_deleted'] == 0))) { 
						// There might be a chance that the post was deleted during another RegenerateThreads() session, if there are no posts, move on to the next thread.
						if(count($posts) > 0){
							foreach ($posts as $key=>$post) {
								if (($post['file_type'] == 'jpg' || $post['file_type'] == 'gif' || $post['file_type'] == 'png') && $post['parentid'] != 0) {
									$numimages++;
								}
								$posts[$key] = $this->BuildPost($post, false);
							}

							$header_replaced = str_replace("<!sm_threadid>", $thread['id'], $header);
							$this->dwoo_data->assign('numimages', $numimages);
							$this->dwoo_data->assign('replythread', $thread['id']);
							$this->dwoo_data->assign('posts', $posts);
							$this->dwoo_data->assign('file_path', getCLBoardPath($this->board['name'], $this->board['loadbalanceurl_formatted'], ''));
							if ($this->board['type'] != 2){
								$postbox_replaced = str_replace("<!sm_threadid>", $thread['id'], $postbox);
							}
							else {
								$postbox_replaced = $this->Postbox($thread['id']);
							}
							$reply	 = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_reply_header.tpl', $this->dwoo_data);
							$content = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_thread.tpl', $this->dwoo_data);
							if (!isset($footer)) $footer = $this->Footer(false, (microtime_float() - $executiontime_start_thread), (($this->board['type'] == 1) ? (true) : (false)));
							$content = $header_replaced.$reply.$postbox_replaced.$content.$footer;

							$content = str_replace("\t", '',$content);
							$content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

							$this->PrintPage(KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/res/' . $thread['id'] . '.html', $content, $this->board['name']);
							if (KU_FIRSTLAST) {

								$replycount = (count($posts)-1);
								if ($replycount > 50) {
									$this->dwoo_data->assign('replycount', $replycount);
									$this->dwoo_data->assign('modifier', "last50");

									// Grab the last 50 replies
									$posts50 = array_slice($posts, -50, 50);

									// Add on the OP
									array_unshift($posts50, $posts[0]);
									
									$this->dwoo_data->assign('posts', $posts50);

									$content = $this->dwoo->get(KU_TEMPLATEDIR . '/img_thread.tpl', $this->dwoo_data);
									$content = $header_replaced.$reply.$postbox_replaced.$content.$footer;
									$content = str_replace("\t", '',$content);
									$content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

									unset($posts50);

									$this->PrintPage(KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/res/' . $thread['id'] . '+50.html', $content, $this->board['name']);
									if ($replycount > 100) {
										$this->dwoo_data->assign('modifier', "first100");

										// Grab the first 100 posts
										$posts100 = array_slice($posts, 0, 100);

										$this->dwoo_data->assign('posts', $posts100);

										$content = $this->dwoo->get(KU_TEMPLATEDIR . '/img_thread.tpl', $this->dwoo_data);
										$content = $header_replaced.$reply.$postbox_replaced.$content.$footer;
										$content = str_replace("\t", '',$content);
										$content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

										unset($posts100);
										
										$this->PrintPage(KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/res/' . $thread['id'] . '-100.html', $content, $this->board['name']);
									}
									$this->dwoo_data->assign('modifier', "");
								}
							}
						}
					}
				}
			}
		} else {
			$executiontime_start_thread = microtime_float();
			// Build only that thread
			$thread = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $this->board['id'] . " AND (`id` = " . $id . " OR `parentid` = " . $id . ") " . (($this->board['type'] != 1) ? ("AND `IS_DELETED` = 0") : ("")) . " ORDER BY `id` ASC");
			if ($this->board['type'] != 1 || ((isset($thread[0]['IS_DELETED']) && $thread[0]['IS_DELETED'] == 0) || (isset($thread[0]['is_deleted']) && $thread[0]['is_deleted'] == 0))) { 
				foreach ($thread as $key=>$post) {
					if (($post['file_type'] == 'jpg' || $post['file_type'] == 'gif' || $post['file_type'] == 'png') && $post['parentid'] != 0) {
						$numimages++;
					}
					$thread[$key] = $this->BuildPost($post, false);
				}
				$header = $this->PageHeader($id);
				$postbox = $this->Postbox($id);
				$this->dwoo_data->assign('numimages', $numimages);
				$header = str_replace("<!sm_threadid>", $id, $header);

				$this->dwoo_data->assign('replythread', $id);
				if ($this->board['type'] != 2){
					$postbox = str_replace("<!sm_threadid>", $id, $postbox);
				}

				$this->dwoo_data->assign('threadid', $thread[0]['id']);
				$this->dwoo_data->assign('posts', $thread);
				$this->dwoo_data->assign('file_path', getCLBoardPath($this->board['name'], $this->board['loadbalanceurl_formatted'], ''));
				
				$postbox = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_reply_header.tpl', $this->dwoo_data).$postbox;
				$content = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_thread.tpl', $this->dwoo_data);
				
				if (!isset($footer)) $footer = $this->Footer(false, (microtime_float() - $executiontime_start_thread), (($this->board['type'] == 1) ? (true) : (false)));
				$content = $header.$postbox.$content.$footer;

				$content = str_replace("\t", '',$content);
				$content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

				$this->PrintPage(KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/res/' . $id . '.html', $content, $this->board['name']);
				if (KU_FIRSTLAST) {
					$replycount = $tc_db->GetOne("SELECT COUNT(`id`) FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $this->board['id'] . " AND `parentid` = " . $id . " AND `IS_DELETED` = 0");
					if ($replycount > 50) {
						$this->dwoo_data->assign('replycount', $replycount);
						$this->dwoo_data->assign('modifier', "last50");

						// Grab the last 50 replies
						$posts50 = array_slice($thread, -50, 50);

						// Add the thread to the top of this, since it wont be included in the result
						array_unshift($posts50, $thread[0]); 

						$this->dwoo_data->assign('posts', $posts50);

						$content = $this->dwoo->get(KU_TEMPLATEDIR . '/img_thread.tpl', $this->dwoo_data);
						$content = $header.$reply.$postbox.$content.$footer;
						$content = str_replace("\t", '',$content);
						$content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

						unset($posts50);					

						$this->PrintPage(KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/res/' . $id . '+50.html', $content, $this->board['name']);
						if ($replycount > 100) {
							$this->dwoo_data->assign('modifier', "first100");

							// Grab the first 100 posts
							$posts100 = array_slice($thread, 0, 100);

							$this->dwoo_data->assign('posts', $posts100);

							$this->dwoo_data->assign('posts', $posts);
							$content = $this->dwoo->get(KU_TEMPLATEDIR . '/img_thread.tpl', $this->dwoo_data);
							$content = $header.$reply.$postbox.$content.$footer;
							$content = str_replace("\t", '',$content);
							$content = str_replace("&nbsp;\r\n", '&nbsp;',$content);

							unset($posts100);

							$this->PrintPage(KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/res/' . $id . '-100.html', $content, $this->board['name']);
						}
						$this->dwoo_data->assign('modifier', "");
					}
				}
				/*--------------------- Send message to node! ---------------------*/
				//elephant_emit($id);
				/*-----------------------------------------------------------------*/
			}
		}
	}

	function BuildPost($post, $page) {
		global $CURRENTLOCALE;
		if ($this->board['type'] == 1 && ((isset($post['IS_DELETED']) && $post['IS_DELETED'] == 1) || (isset($post['is_deleted']) && $post['is_deleted'] == 1))) { 
			$post['name'] = '';
			$post['email'] = '';
			$post['tripcode'] = _gettext('Deleted');
			$post['message'] = '<font color="gray">'._gettext('This post has been deleted.').'</font>';
		}
		$dateEmail = (empty($this->board['anonymous'])) ? $post['email'] : 0;
		//by Snivy
		if(KU_CUTPOSTS) {
			$post['message'] = stripslashes(formatLongMessage($post['message'], $this->board['name'], (($post['parentid'] == 0) ? ($post['id']) : ($post['parentid'])), $page));
		}
		else {
			$post['message'] = stripslashes($post['message']);
		}
		$post['timestamp_formatted'] = formatDate($post['timestamp'], 'post', $CURRENTLOCALE, $dateEmail);
		$post['reflink'] = formatReflink($this->board['name'], (($post['parentid'] == 0) ? ($post['id']) : ($post['parentid'])), $post['id'], $CURRENTLOCALE);
		if (isset($this->board['filetypes']) && in_array($post['file_type'], $this->board['filetypes'])) {
			$post['videobox'] = embeddedVideoBox($post);
		}
		if ($post['file_type'] == 'mp3' && $this->board['loadbalanceurl'] == '') {
			//Grab the ID3 info. TODO: Make this work for load-balanced boards.
			// include getID3() library

			require_once(KU_ROOTDIR . 'lib/getid3/getid3.php');

			// Initialize getID3 engine
			$getID3 = new getID3;

			$post['id3'] = $getID3->analyze(KU_BOARDSDIR.$this->board['name'].'/src/'.$post['file'].'.mp3');
			getid3_lib::CopyTagsToComments($post['id3']);
		}
		if ($post['file_type']!='jpg'&&$post['file_type']!='gif'&&$post['file_type']!='png'&&$post['file_type']!=''&&!in_array($post['file_type'], $this->board['filetypes'])) {
			if(!isset($filetype_info[$post['file_type']])) $filetype_info[$post['file_type']] = getfiletypeinfo($post['file_type']);
			$post['nonstandard_file'] = KU_WEBPATH . '/inc/filetypes/' . $filetype_info[$post['file_type']][0];
			if($post['thumb_w']!=0&&$post['thumb_h']!=0) {
				if(file_exists(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$post['file'].'s.jpg'))
					$post['nonstandard_file'] = KU_WEBPATH . '/' .$this->board['name'].'/thumb/'.$post['file'].'s.jpg';
				elseif(file_exists(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$post['file'].'s.png'))
					$post['nonstandard_file'] = KU_WEBPATH . '/' .$this->board['name'].'/thumb/'.$post['file'].'s.png';
				elseif(file_exists(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$post['file'].'s.gif'))
					$post['nonstandard_file'] = KU_WEBPATH . '/' .$this->board['name'].'/thumb/'.$post['file'].'s.gif';
				else {
					$post['thumb_w'] = $filetype_info[$post['file_type']][1];
					$post['thumb_h'] = $filetype_info[$post['file_type']][2];
				}
			}
			else {
				$post['thumb_w'] = $filetype_info[$post['file_type']][1];
				$post['thumb_h'] = $filetype_info[$post['file_type']][2];
			}
		}
		
		return $post;
	}
	
	/**
	 * Build the page header
	 *
	 * @param integer $replythread The ID of the thread the header is being build for.  0 if it is for a board page
	 * @param integer $liststart The number which the thread list starts on (text boards only)
	 * @param integer $liststooutput The number of list pages which will be generated (text boards only)
	 * @return string The built header
	 */
	function PageHeader($replythread = '0', $liststart = '0', $liststooutput = '-1') {
		global $tc_db, $CURRENTLOCALE;

		$tpl = Array();

		$tpl['htmloptions'] = ((KU_LOCALE == 'he' && empty($this->board['locale'])) || $this->board['locale'] == 'he') ? ' dir="rtl"' : '' ;

		$tpl['title'] = '';

		if (KU_DIRTITLE) {
			$tpl['title'] .= '/' . $this->board['name'] . '/ - ';
		}
		$tpl['title'] .= $this->board['desc'];

		$ad_top = 185;
		$ad_right = 25;
		if ($this->board['type']==1) {
			$ad_top -= 50;
		} else {
			if ($replythread!=0) {
				$ad_top += 50;
			}
		}
		if ($this->board['type']==2) {
			$ad_top += 40;
		}
		$this->dwoo_data->assign('title', $tpl['title']);
		$this->dwoo_data->assign('htmloptions', $tpl['htmloptions']);
		$this->dwoo_data->assign('locale', $CURRENTLOCALE);
		$this->dwoo_data->assign('ad_top', $ad_top);
		$this->dwoo_data->assign('ad_right', $ad_right);
		$this->dwoo_data->assign('board', $this->board);
		$this->dwoo_data->assign('replythread', $replythread);
		if ($this->board['type'] != 1) {
			$topads = $tc_db->GetOne("SELECT code FROM `" . KU_DBPREFIX . "ads` WHERE `position` = 'top' AND `disp` = '1'");
			$this->dwoo_data->assign('topads', $topads);
			// #snivystuff include alien style
			$styles =  explode(':', KU_STYLES);
			$defaultstyle = $this->board['defaultstyle'];
			if(!empty($defaultstyle)) {
				if(!in_array($defaultstyle, $styles)) {
					$custom_style_version = $tc_db->GetOne("SELECT `version` FROM `customstyles` WHERE `name` = '".$defaultstyle."'");
					if(count($custom_style_version) > 0) {
						$styles[]= $defaultstyle;
						$this->dwoo_data->assign('customstyle', $defaultstyle);
						$this->dwoo_data->assign('csver', $custom_style_version);
					}
				}
				else { $this->dwoo_data->assign('customstyle', false); }
			}
			else $defaultstyle = KU_DEFAULTSTYLE;
			$this->dwoo_data->assign('ku_styles', $styles);
			$this->dwoo_data->assign('ku_defaultstyle', $defaultstyle);
		} else {
			$this->dwoo_data->assign('ku_styles', explode(':', KU_TXTSTYLES));
			$this->dwoo_data->assign('ku_defaultstyle', (!empty($this->board['defaultstyle']) ? ($this->board['defaultstyle']) : (KU_DEFAULTTXTSTYLE)));
		}
		$this->dwoo_data->assign('boardlist', $this->board['boardlist']);

		$global_header = $this->dwoo->get(KU_TEMPLATEDIR . '/global_board_header.tpl', $this->dwoo_data);

		if ($this->board['type'] != 1) {
			$header = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_header.tpl', $this->dwoo_data);
		} else {
			if ($liststooutput == -1) {
				$this->dwoo_data->assign('isindex', true);
			} else {
				$this->dwoo_data->assign('isindex', false);
			}
			if ($replythread != 0) $this->dwoo_data->assign('isthread', true);
			$header = $this->dwoo->get(KU_TEMPLATEDIR . '/txt_header.tpl', $this->dwoo_data);

			if ($replythread == 0) {
				$startrecord = ($liststooutput >= 0 || $this->board['compactlist']) ? 40 : KU_THREADSTXT ;
				$threads = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $tc_db->qstr($this->board['id']) . " AND `parentid` = 0 AND `IS_DELETED` = 0 ORDER BY `stickied` DESC, `bumped` DESC LIMIT " . $startrecord . " OFFSET " . $liststart);
				foreach($threads AS $key=>$thread) {
					$replycount = $tc_db->GetOne("SELECT COUNT(`id`) FROM `" . KU_DBPREFIX . "posts` WHERE `boardid` = " . $tc_db->qstr($this->board['id']) . " AND `parentid` = " . $thread['id']);
					$threads[$key]['replies'] = $replycount;
				}
				$this->dwoo_data->assign('threads', $threads);
				$header .= $this->dwoo->get(KU_TEMPLATEDIR . '/txt_threadlist.tpl', $this->dwoo_data);
			}
		}

		return $global_header.$header;
	}

	/**
	 * Build the page header for an oekaki posting
	 *
	 * @param integer $replyto The ID of the thread being replied to.  0 for a new thread
	 */
	function OekakiHeader($replyto, $postoek) {
		$executiontime_start = microtime_float();
		$this->InitializeDwoo();

		$page = $this->PageHeader();
		$this->dwoo_data->assign('replythread', $replyto);
		$page .= $this->Postbox();

		$executiontime_stop = microtime_float();

		$page .= $this->Footer(false, ($executiontime_stop - $executiontime_start));

		$this->PrintPage('', $page, true);
	}

	/**
	 * Generate the postbox area
	 *
	 * @param integer $replythread The ID of the thread being replied to.  0 if not replying
	 * @param string $postboxnotice The postbox notice
	 * @return string The generated postbox
	 */
	function Postbox($replythread = 0) {
		global $tc_db;
		if (KU_BLOTTER && $this->board['type'] != 1) {
			$this->dwoo_data->assign('blotter', getBlotter());
			$this->dwoo_data->assign('blotter_updated', getBlotterLastUpdated());
		}
		$postbox = '';

		if ($this->board['type'] == 2 && $replythread > 0) {
			$oekposts = $tc_db->GetAll("SELECT `id` FROM `" . KU_DBPREFIX."posts` WHERE `boardid` = " . $this->board['id']." AND (`id` = ".$replythread." OR `parentid` = ".$replythread.") AND `file` != '' AND `file` != 'removed' AND `file_type` IN ('jpg', 'gif', 'png') AND `IS_DELETED` = 0 ORDER BY `parentid` ASC, `timestamp` ASC");
			$this->dwoo_data->assign('oekposts', $oekposts);
		}
		if(($this->board['type'] == 1 && $replythread == 0) || $this->board['type'] != 1) {
			$postbox .= $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_post_box.tpl', $this->dwoo_data);
		}
		return $postbox;
	}

	/**
	 * Display the user-defined list of boards found in boards.html
	 * * Snivy added section description for better header
	 * @param boolean $is_textboard If the board this is being displayed for is a text board
	 * @return string The board list
	 */
	function DisplayBoardList($is_textboard = false) {
		if (KU_GENERATEBOARDLIST) {
			global $tc_db;
			$output = '';
			$results = $tc_db->GetAll("SELECT `id`,`name`,`abbreviation` FROM `" . KU_DBPREFIX . "sections` ORDER BY `order` ASC");
			$boards = array();
			foreach($results AS $line) {
				$boards[$line['id']]['nick'] = htmlspecialchars($line['name']);
				$boards[$line['id']]['abbreviation'] = htmlspecialchars($line['abbreviation']);
				$results2 = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "boards` WHERE `section` = '" . $line['id'] . "' ORDER BY `order` ASC, `name` ASC");
				foreach($results2 AS $line2) {
					$boards[$line['id']][$line2['id']]['name'] = htmlspecialchars($line2['name']);
					$boards[$line['id']][$line2['id']]['desc'] = htmlspecialchars($line2['desc']);
				}
			}
		} else {
			$boards = KU_ROOTDIR . 'boards.html';
		}

		return $boards;
	}
	/*function DisplayBoardList($is_textboard = false) {
		if (KU_GENERATEBOARDLIST) {
			global $tc_db;
	//snivy was here
			$output = '';
			$results = $tc_db->GetAll("SELECT `id` FROM `" . KU_DBPREFIX . "sections` ORDER BY `order` ASC");
			$boards = array();
			foreach($results AS $line) {
				$results2 = $tc_db->GetAll("SELECT * FROM `" . KU_DBPREFIX . "boards` WHERE `section` = '" . $line['id'] . "' ORDER BY `order` ASC, `name` ASC");
				foreach($results2 AS $line2) {
					$boards[$line['id']][$line2['id']]['name'] = htmlspecialchars($line2['name']);
					$boards[$line['id']][$line2['id']]['desc'] = htmlspecialchars($line2['desc']);
				}
			}
		} else {
			$boards = KU_ROOTDIR . 'boards.html';
		}

		return $boards;
	}*/


	/**
	 * Display the page footer
	 *
	 * @param boolean $noboardlist Force the board list to not be displayed
	 * @param string $executiontime The time it took the page to be created
	 * @param boolean $hide_extra Hide extra footer information, and display the manage link
	 * @return string The generated footer
	 */
	function Footer($noboardlist = false, $executiontime = '', $hide_extra = false) {
		global $tc_db, $dwoo, $dwoo_data;

		$footer = '';

		if ($hide_extra || $noboardlist) $this->dwoo_data->assign('boardlist', '');

		if ($executiontime != '') $this->dwoo_data->assign('executiontime', round($executiontime, 2));
		
		$botads = $tc_db->GetOne("SELECT code FROM `" . KU_DBPREFIX . "ads` WHERE `position` = 'bot' AND `disp` = '1'");
		$this->dwoo_data->assign('botads', $botads);
		$footer = $this->dwoo->get(KU_TEMPLATEDIR . '/' . $this->board['text_readable'] . '_footer.tpl', $this->dwoo_data);
		
		$footer .= $this->dwoo->get(KU_TEMPLATEDIR . '/global_board_footer.tpl', $this->dwoo_data);

		return $footer;
	}

	/**
	 * Finalize the page and print it to the specified filename
	 *
	 * @param string $filename File to print the page to
	 * @param string $contents Page contents
	 * @param string $board Board which the file is being generated for
	 * @return string The page contents, if requested
	 */
	function PrintPage($filename, $contents, $board) {

		if ($board !== true) {
			print_page($filename, $contents, $board);
		} else {
			echo $contents;
		}
	}

	/**
	 * Initialize the instance of smary which will be used for generating pages
	 */
	function InitializeDwoo() {

		require_once KU_ROOTDIR . 'lib/dwoo.php';
		$this->dwoo = new Dwoo();
		$this->dwoo_data = new Dwoo_Data();

		$this->dwoo_data->assign('cwebpath', getCWebpath());
		$this->dwoo_data->assign('boardpath', getCLBoardPath());
	}

	/**
	 * Enable/disable archive mode
	 *
	 * @param boolean $mode True/false for enabling/disabling archive mode
	 */
	function ArchiveMode($mode) {
		$this->archive_dir = ($mode && $this->board['enablearchiving'] == 1) ? '/arch' : '';
	}
}

/**
 * Post class
 *
 * Used for post insertion, deletion, and reporting.
 *
 * @package kusaba
 */
class Post extends Board {
	// Declare the public variables
	var $post = Array();

	function __construct($postid, $board, $boardid, $is_inserting = false) {
		global $tc_db;

		$results = $tc_db->GetAll("SELECT * FROM `".KU_DBPREFIX."posts` WHERE `boardid` = '" . $boardid . "' AND `id` = ".$tc_db->qstr($postid)." LIMIT 1");
		if (count($results)==0&&!$is_inserting) {
			exitWithErrorPage('Invalid post ID.');
		} elseif ($is_inserting) {
			parent::__construct($board, false);
		} else {
			foreach ($results[0] as $key=>$line) {
				if (!is_numeric($key)) $this->post[$key] = $line;
			}
			$results = $tc_db->GetAll("SELECT `cleared` FROM `".KU_DBPREFIX."reports` WHERE `postid` = ".$tc_db->qstr($this->post['id'])." LIMIT 1");
			if (count($results)>0) {
				foreach($results AS $line) {
					$this->post['isreported'] = ($line['cleared'] == 0) ? true : 'cleared';
				}
			} else {
				$this->post['isreported'] = false;
			}
			$this->post['isthread'] = ($this->post['parentid'] == 0) ? true : false;
			if (empty($this->board) || $this->board['name'] != $board) {
				$this->Board($board, false);
			}
		}
	}

	function Delete($allow_archive = false) {
		global $tc_db;

		$i = 0;
		if ($this->post['isthread'] == true) {
			if ($allow_archive && $this->board['enablearchiving'] == 1 && $this->board['loadbalanceurl'] == '') {
				$this->ArchiveMode(true);
				$this->RegenerateThreads($this->post['id']);
				@copy(KU_BOARDSDIR . $this->board['name'] . '/src/' . $this->post['file'] . '.' . $this->post['filetype'], KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/src/' . $this->post['file'] . '.' . $this->post['filetype']);
				@copy(KU_BOARDSDIR . $this->board['name'] . '/thumb/' . $this->post['file'] . 's.' . $this->post['filetype'], KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/thumb/' . $this->post['file'] . 's.' . $this->post['filetype']);
			}
			$results = $tc_db->GetAll("SELECT `id`, `file`, `file_type` FROM `".KU_DBPREFIX."posts` WHERE `boardid` = '" . $this->board['id'] . "' AND `IS_DELETED` = 0 AND `parentid` = ".$tc_db->qstr($this->post['id']));
			foreach($results AS $line) {
				$i++;
				if ($allow_archive && $this->board['enablearchiving'] == 1) {
					@copy(KU_BOARDSDIR . $this->board['name'] . '/src/' . $line['file'] . '.' . $line['file_type'], KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/src/' . $line['file'] . '.' . $line['file_type']);
					@copy(KU_BOARDSDIR . $this->board['name'] . '/thumb/' . $line['file'] . 's.' . $line['file_type'], KU_BOARDSDIR . $this->board['name'] . $this->archive_dir . '/thumb/' . $line['file'] . 's.' . $line['file_type']);
				}
			}
			if ($allow_archive && $this->board['enablearchiving'] == 1) {
				$this->ArchiveMode(false);
			}
			@unlink(KU_BOARDSDIR.$this->board['name'].'/res/'.$this->post['id'].'.html');
			@unlink(KU_BOARDSDIR.$this->board['name'].'/res/'.$this->post['id'].'-100.html');
			@unlink(KU_BOARDSDIR.$this->board['name'].'/res/'.$this->post['id'].'+50.html');
			$this->DeleteFile(false, true);
			foreach($results AS $line) {
				$tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `IS_DELETED` = 1 , `deleted_timestamp` = '" . time() . "' WHERE `boardid` = '" . $this->board['id'] . "' AND `id` = '".$line['id']."' AND `parentid` = ".$tc_db->qstr($this->post['id']));
				clearPostCache($line['id'], $this->board['name']);
			}
			$tc_db->Execute("DELETE FROM `".KU_DBPREFIX."watchedthreads` WHERE `threadid` = ".$tc_db->qstr($this->post['id'])." AND `board` = '".$this->board['name']."'");
			$tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `IS_DELETED` = 1 , `deleted_timestamp` = '" . time() . "' WHERE `boardid` = '" . $this->board['id'] . "' AND `id` = ".$tc_db->qstr($this->post['id']));
			clearPostCache($this->post['id'], $this->board['name']);

			return $i.' ';
		} else {
			$this->DeleteFile(false);
			$tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `IS_DELETED` = 1 , `deleted_timestamp` = '" . time() . "' WHERE `boardid` = '" . $this->board['id'] . "' AND `id` = ".$tc_db->qstr($this->post['id']));
			$tc_db->Execute('UPDATE `'.KU_DBPREFIX.'posts` AS t1, (SELECT `timestamp` FROM `'.KU_DBPREFIX.'posts` WHERE (`id`=? OR (`parentid`=? AND `email`!="sage")) AND `IS_DELETED`="0" AND `boardid`=?  ORDER BY TIMESTAMP DESC LIMIT 1) AS t2 SET t1.`bumped` = t2.`timestamp` WHERE t1.`id`=? AND `boardid`=?', array($this->post['parentid'], $this->post['parentid'], $this->board['id'], $this->post['parentid'], $this->board['id']));
			clearPostCache($this->post['id'], $this->board['name']);
			return true;
		}
	}

	function DeleteFile($update_to_removed = true, $whole_thread = false) {
		global $tc_db;
		if ($whole_thread && $this->post['isthread']) {
			$results = $tc_db->GetAll("SELECT `id`, `file`, `file_type` FROM `".KU_DBPREFIX."posts` WHERE `boardid` = " . $this->board['id'] . " AND `IS_DELETED` = 0 AND `parentid` = ".$tc_db->qstr($this->post['id']));
			if (count($results)>0) {
				foreach($results AS $line) {
					if ($line['file'] != '' && $line['file'] != 'removed') {
						if ($this->board['loadbalanceurl'] != '') {
							$this->loadbalancer->Delete($line['file'], $line['file_type']);
						} else {
							@unlink(KU_BOARDSDIR.$this->board['name'].'/src/'.$line['file'].'.'.$line['file_type']);
							@unlink(KU_BOARDSDIR.$this->board['name'].'/src/'.$line['file'].'.pch');
							@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$line['file'].'s.'.$line['file_type']);
							@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$line['file'].'c.'.$line['file_type']);
							if ($line['file_type'] == 'mp3') {
								@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$line['file'].'s.jpg');
								@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$line['file'].'s.png');
								@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$line['file'].'s.gif');
							}
						}
						if ($update_to_removed) {
							$tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `file` = 'removed', `file_md5` = '' WHERE `boardid` = '" . $this->board['id'] . "' AND `id` = ".$line['id']);
							clearPostCache($line['id'], $this->board['name']);
						}
					}
				}
			}
			$this->DeleteFile($update_to_removed);
		} else {
			if ($this->post['file']!=''&&$this->post['file']!='removed') {
				if ($this->board['loadbalanceurl'] != '') {
					$this->loadbalancer->Delete($this->post['file'], $this->post['filetype']);
				} else {
						@unlink(KU_BOARDSDIR.$this->board['name'].'/src/'.$this->post['file'].'.'.$this->post['file_type']);
						@unlink(KU_BOARDSDIR.$this->board['name'].'/src/'.$this->post['file'].'.pch');
						@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$this->post['file'].'s.'.$this->post['file_type']);
						@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$this->post['file'].'c.'.$this->post['file_type']);
						if ($this->post['file_type'] == 'mp3') {
							@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$this->post['file'].'s.jpg');
							@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$this->post['file'].'s.png');
							@unlink(KU_BOARDSDIR.$this->board['name'].'/thumb/'.$this->post['file'].'s.gif');
						}
				}
				if ($update_to_removed) {
					$tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `file` = 'removed', `file_md5` = '' WHERE `boardid` = '" . $this->board['id'] . "' AND `id` = ".$tc_db->qstr($this->post['id']));
					clearPostCache($this->post['id'], $this->board['name']);
				}
			}
		}
	}

	function Insert($parentid, $name, $tripcode, $email, $subject, $message, $filename, $file_original, $filetype, $file_md5, $image_w, $image_h, $filesize, $thumb_w, $thumb_h, $password, $timestamp, $bumped, $ip, $posterauthority, $stickied, $locked, $boardid, $country) {
		global $tc_db;

		$query = "INSERT INTO `".KU_DBPREFIX."posts` ( `parentid` , `boardid`, `name` , `tripcode` , `email` , `subject` , `message` , `file` , `file_original`, `file_type` , `file_md5` , `image_w` , `image_h` , `file_size` , `file_size_formatted` , `thumb_w` , `thumb_h` , `password` , `timestamp` , `bumped` , `ip` , `ipmd5` , `posterauthority` , `stickied` , `locked`, `country` ) VALUES ( ".$tc_db->qstr($parentid).", ".$tc_db->qstr($boardid).", ".$tc_db->qstr($name).", ".$tc_db->qstr($tripcode).", ".$tc_db->qstr($email).", ".$tc_db->qstr($subject).", ".$tc_db->qstr($message).", ".$tc_db->qstr($filename).", ".$tc_db->qstr($file_original).", ".$tc_db->qstr($filetype).", ".$tc_db->qstr($file_md5).", ".$tc_db->qstr(intval($image_w)).", ".$tc_db->qstr(intval($image_h)).", ".$tc_db->qstr($filesize).", ".$tc_db->qstr(ConvertBytes($filesize)).", ".$tc_db->qstr($thumb_w).", ".$tc_db->qstr($thumb_h).", ".$tc_db->qstr($password).", ".$tc_db->qstr($timestamp).", ".$tc_db->qstr($bumped).", ".$tc_db->qstr(md5_encrypt($ip, KU_RANDOMSEED)).", '".md5($ip)."', ".$tc_db->qstr($posterauthority).", ".$tc_db->qstr($stickied).", ".$tc_db->qstr($locked).", ".$tc_db->qstr($country)." )";
		$tc_db->Execute($query);
		$id = $tc_db->Insert_Id();
		if(!$id || KU_DBTYPE == 'sqlite') {
			// Non-mysql installs don't return the insert ID after insertion, we need to manually get it.
			$id = $tc_db->GetOne("SELECT `id` FROM `".KU_DBPREFIX."posts` WHERE `boardid` = ".$tc_db->qstr($boardid)." AND timestamp = ".$tc_db->qstr($timestamp)." AND `ipmd5` = '".md5($ip)."' LIMIT 1");
		}

		if ($id == 1 && $this->board['start'] > 1) {
			$tc_db->Execute("UPDATE `".KU_DBPREFIX."posts` SET `id` = '".$this->board['start']."' WHERE `boardid` = ".$boardid);
			return $this->board['start'];
		}
		return $id;
	}

	function Report() {
		global $tc_db;

		return $tc_db->Execute("INSERT INTO `".KU_DBPREFIX."reports` ( `board` , `postid` , `when` , `ip`, `reason` ) VALUES ( " . $tc_db->qstr($this->board['name']) . " , " . $tc_db->qstr($this->post['id']) . " , ".time()." , '" . md5_encrypt($_SERVER['REMOTE_ADDR'], KU_RANDOMSEED) . "', " . $tc_db->qstr($_POST['reportreason']) . " )");
	}
}

?>