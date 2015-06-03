<?php

	/*
	Plugin Name: FormCraft Campaign Monitor Add-On
	Plugin URI: http://formcraft-wp.com/addons/campaign-monitor/
	Description: Campaign Monitor Add-On for FormCraft
	Author: nCrafts
	Author URI: http://formcraft-wp.com/
	Version: 1.0.5
	Text Domain: formcraft-cm
	*/

	global $fc_meta, $fc_forms_table, $fc_submissions_table, $fc_views_table, $fc_files_table, $wpdb;
	$fc_forms_table = $wpdb->prefix . "formcraft_3_forms";
	$fc_submissions_table = $wpdb->prefix . "formcraft_3_submissions";
	$fc_views_table = $wpdb->prefix . "formcraft_3_views";
	$fc_files_table = $wpdb->prefix . "formcraft_3_files";

	add_action('formcraft_after_save', 'formcraft_campaign_trigger', 10, 4);
	function formcraft_campaign_trigger($content, $meta, $raw_content, $integrations)
	{
		global $fc_final_response;
		if ( in_array('Campaign Monitor', $integrations['not_triggered']) ){ return false; }
		$campaign = formcraft_get_addon_data('Campaign', $content['Form ID']);

		if (!$campaign){return false;}
		if (!isset($campaign['validKey']) || empty($campaign['validKey']) ){return false;}
		if (!isset($campaign['Map'])){return false;}

		$submit_data = array();
		foreach ($campaign['Map'] as $key => $line) {
			$submit_data[$line['listID']]['CustomFields'] = isset($submit_data[$line['listID']]['CustomFields']) ? $submit_data[$line['listID']]['CustomFields'] : array();
			$submit_data[$line['listID']]['Resubscribe'] = false;
			if ($line['columnID']=='EmailAddress')
			{
				$email = fc_template($content, $line['formField']);
				if ( !filter_var($email,FILTER_VALIDATE_EMAIL) ) { continue; }
				$submit_data[$line['listID']]['EmailAddress'] = $email;
			}
			else if ($line['columnID']=='Name')
			{
				$name = fc_template($content, $line['formField']);
				$name = trim(preg_replace('/\s*\[[^)]*\]/', '', $name));
				$submit_data[$line['listID']]['Name'] = $name;
			}
			else
			{
				$cf_val = fc_template($content, $line['formField']);
				$cf_val = trim(preg_replace('/\s*\[[^)]*\]/', '', $cf_val));
				$submit_data[$line['listID']]['CustomFields'][] = array('Key'=>$line['columnID'],'Value'=>$cf_val);
			}
		}

		require_once('api/csrest_general.php');
		require_once('api/csrest_subscribers.php');
		$auth = array('api_key' => $campaign['validKey']);
		foreach ($submit_data as $listID => $listData) {
			if ( empty($listData['EmailAddress']) ) { continue; }
			$wrap = new CS_REST_Subscribers($listID, $auth);
			$result = $wrap->add($listData);
			if($result->was_successful()) {
				$fc_final_response['debug']['success'][] = "Campaign Monitor: Added ".$listData['EmailAddress'];
			} else {
				if ( isset($result->response->Message) )
				{
					$fc_final_response['debug']['failed'][] = "Campaign Monitor Error: ".$result->response->Message;
				}
				else
				{
					$fc_final_response['debug']['failed'][] = "Campaign Monitor Error";					
				}
			}
		}
	}

	add_action('formcraft_addon_init', 'formcraft_campaign_addon');
	add_action('formcraft_addon_scripts', 'formcraft_campaign_scripts');

	function formcraft_campaign_addon()
	{
		register_formcraft_addon('CM_printContent', 195, 'Campaign Monitor', 'CMController', plugins_url('assets/logo.png', __FILE__ ), plugin_dir_path( __FILE__ ).'templates/', 1);	
	}
	function formcraft_campaign_scripts()
	{
		wp_enqueue_script('fc-cm-main-js', plugins_url( 'assets/builder.js', __FILE__ ));
		wp_enqueue_style('fc-cm-main-css', plugins_url( 'assets/builder.css', __FILE__ ));
	}

	add_action( 'wp_ajax_formcraft_campaign_test_api', 'formcraft_campaign_test_api' );
	function formcraft_campaign_test_api()
	{
		$key = $_GET['key'];
		require_once('api/csrest_general.php');
		$auth = array('api_key' => $key);
		$wrap = new CS_REST_General($auth);
		$result = $wrap->get_clients();

		if ( $result->was_successful() )
		{
			echo json_encode(array('success'=>'true','clients'=>$result->response));
			die();
		}
		else
		{
			echo json_encode(array('failed'=>'true'));
			die();
		}
	}

	add_action( 'wp_ajax_formcraft_campaign_get_lists', 'formcraft_campaign_get_lists' );
	function formcraft_campaign_get_lists()
	{
		$key = $_GET['key'];
		$id = $_GET['id'];
		require_once('api/csrest_general.php');
		require_once('api/csrest_clients.php');
		$auth = array('api_key' => $key);
		$wrap = new CS_REST_Clients($id, $auth);
		$result = $wrap->get_lists();

		if($result->was_successful()) {
			echo json_encode(array('success'=>'true','lists'=>$result->response));
			die();
		} else {
			echo json_encode(array('failed'=>'true'));
			die();
		}
	}
	add_action( 'wp_ajax_formcraft_campaign_get_columns', 'formcraft_campaign_get_columns' );
	function formcraft_campaign_get_columns()
	{
		$key = $_GET['key'];
		$id = $_GET['id'];
		require_once('api/csrest_general.php');
		require_once('api/csrest_lists.php');
		$auth = array('api_key' => $key);
		$wrap = new CS_REST_Lists($id, $auth);
		$result = $wrap->get_custom_fields();

		$columns = array();
		$columns[] = array('Key'=>'EmailAddress','FieldName'=>'Email');
		$columns[] = array('Key'=>'Name','FieldName'=>'Name');
		if ( !empty($result->response) && count($result->response)>0 )
		{
			foreach ($result->response as $key => $value) {
				$columns[] = array('Key'=>$value->Key,'FieldName'=>$value->FieldName);
			}
		}

		if($result->was_successful()) {
			echo json_encode(array('success'=>'true','columns'=>$columns));
			die();
		} else {
			echo json_encode(array('failed'=>'true'));
			die();
		}	
	}

	function CM_printContent()
	{

		?>
		<div id='cm-cover' id='cm-valid-{{Addons.Campaign.showOptions}}'>
			<div class='loader'>
				<div class="fc-spinner small">
					<div class="bounce1"></div><div class="bounce2"></div><div class="bounce3"></div>
				</div>
			</div>
			<div class='help-link'>
				<a class='trigger-help' data-post-id='205'><?php _e('how does this work?','formcraft-campaign'); ?></a>
			</div>
			<div class='api-key hide-{{Addons.Campaign.showOptions}}'>	
				<input placeholder='<?php _e('Enter API Key','formcraft-campaign') ?>' style='width: 77%; margin-right: 3%; margin-left:0' type='text' ng-model='Addons.Campaign.api_key'><button ng-click='testKey()' style='width: 20%; margin: 0' class='button blue'><?php _e('Check','formcraft-campaign') ?></button>
			</div>
			<div ng-show='Addons.Campaign.showOptions'>
				<select ng-model='Addons.Campaign.selectedClient' ng-options='client.ClientID as client.Name for client in Addons.Campaign.clients' style='width: 100%; margin: 9px 0 0 0'>
				</select>				
			</div>
			<div ng-show='Addons.Campaign.showOptions'>
				<div id='mapped-cm' class='nos-{{Addons.Campaign.Map.length}}'>
					<div>
						<?php _e('Nothing Here','formcraft-campaign') ?>
					</div>
					<table cellpadding='0' cellspacing='0'>
						<tbody>
							<tr ng-repeat='instance in Addons.Campaign.Map'>
								<td style='width: 30%'>
									<span>{{instance.listName}}</span>
								</td>
								<td style='width: 30%'>
									<span>{{instance.columnName}}</span>
								</td>
								<td style='width: 30%'>
									<span><input type='text' ng-model='instance.formField'/></span>
								</td>
								<td style='width: 10%; text-align: center'>
									<i ng-click='removeMap($index)' class='icon-cancel-circled'></i>
								</td>								
							</tr>
						</tbody>
					</table>
				</div>
				<div id='cm-map'>
					<select class='select-list' ng-model='SelectedList'><option value='' selected="selected"><?php _e('List','formcraft-campaign') ?></option><option ng-repeat='list in CampaignLists' value='{{list.ListID}}'>{{list.Name}}</option></select>

					<select class='select-column' ng-model='SelectedColumn'><option value='' selected="selected"><?php _e('Column','formcraft-campaign') ?></option><option ng-repeat='col in CampaignColumns' value='{{col.Key}}'>{{col.FieldName}}</option></select>

					<input class='select-field' type='text' ng-model='FieldName' placeholder='<?php _e('Form Field','formcraft-campaign') ?>'>
					<button class='button' ng-click='addMap()'><i class='icon-plus'></i></button>
				</div>
			</div>
		</div>
		<?php
	}


	?>