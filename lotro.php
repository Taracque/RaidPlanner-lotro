<?php
/*------------------------------------------------------------------------
# Lotro API Sync Plugin
# com_raidplanner - RaidPlanner Component
# ------------------------------------------------------------------------
# author    Taracque
# copyright Copyright (C) 2011 Taracque. All Rights Reserved.
# @license - http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
# Website: http://www.taracque.hu/raidplanner
-------------------------------------------------------------------------*/
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

class RaidPlannerPluginLotro extends JPlugin
{
	private $guild_id = 0;
	private $rp_params = array();
	private $guild_name = '';

	public function onRPInitGuild( $guildId, $params )
	{
		$db = & JFactory::getDBO();

		$query = "SELECT guild_name,guild_id FROM #__raidplanner_guild WHERE guild_id=" . intval($guildId); 
		$db->setQuery($query);
		if ( $data = $db->loadObject() )
		{
			$this->guild_name = $data->guild_name;
			$this->guild_id = $data->guild_id;
			$this->rp_params = $params;
		} else {
			$this->guild_id = 0;
		}
	}

	public function onRPBeforeSync()
	{
		return true;
	}

	public function onRPSyncGuild( $showOkStatus = false, $syncInterval = 4, $forceSync = false  )
	{
		$db = & JFactory::getDBO();

		$query = "SELECT IF(lastSync IS NULL,-1,DATE_ADD(lastSync, INTERVAL " . intval( $syncInterval ) . " HOUR)-NOW()) AS needSync,guild_name FROM #__raidplanner_guild WHERE guild_id=" . intval($this->guild_id); 
		$db->setQuery($query);
		if ( (!$forceSync) && ( !($needsync = $db->loadResult()) || ( $needsync>=0 ) ) )
		{
			/* Sync not needed, exit */
			return false;
		}

		JLoader::register('RaidPlannerHelper', JPATH_ADMINISTRATOR . '/components/com_raidplanner/helper.php' );

		$guild_id = $this->guild_id;
		
		$developer = $this->rp_params['developer_name'];
		$api_key = $this->rp_params['lotro_api_key'];
		$world_name = $this->rp_params['world_name'];

		$url = "http://data.lotro.com/" . $developer . "/" . $api_key ."/guildroster/w/";
		$url .= rawurlencode( $world_name ) . "/g/";
		$url .= rawurlencode( $this->guild_name );

		$data = RaidPlannerHelper::downloadData( $url );

		$xml_parser =& JFactory::getXMLParser( 'simple' );
		if (( !$xml_parser->loadString( $data ) ) || (!$xml_parser->document) ) {
			if (json_last_error() != JSON_ERROR_NONE)
			{
				JError::raiseWarning('100','LotroSync data decoding error');
				return null;
			}
		}
		if ($xml_parser->document->name() != 'apiresponse')
		{
			JError::raiseWarning('100','LotroSync failed');
			return null;
		}
		if (property_exists($xml_parser->document,'error')) {
			JError::raiseWarning('100', $xml_parser->document->error[0]->attributes('message') );
			return null;
		}
		if (!$guild_id)
		{
			$query = "INSERT INTO #__raidplanner_guild (guild_name) VALUES (".$db->Quote($data->name).")";
			$db->setQuery($query);
			$db->query();
			$guild_id=$db->insertid();
		}

		if (($this->guild_name == $xml_parser->document->guild[0]->attributes('name')) && ($xml_parser->document->guild[0]->attributes('name')!=''))
		{
			$params = array(
				'world_name'	=>	$xml_parser->document->guild[0]->attributes('world'),
			);

			$params = array_merge( $this->rp_params, $params );
			
			$query = "UPDATE #__raidplanner_guild SET
							guild_name=".$db->Quote($xml_parser->document->guild[0]->attributes('name')).",
							params=".$db->Quote(json_encode($params)).",
							lastSync=NOW()
							WHERE guild_id=".intval($guild_id);
			$db->setQuery($query);
			$db->query();

			/* detach characters from guild */
			$query = "UPDATE #__raidplanner_character SET guild_id=0 WHERE guild_id=".intval($guild_id)."";
			$db->setQuery($query);
			$db->query();
			
			/* LOTRO api response looks like this:
			
			<apiresponse>
				<guild name="The crafting union" world="Landroval" theme="Mixed Kinship Theme" memberCount="148">
      				<characters>
      					<character name="Drahc" level="13" class="Champion" race="Dwarf" rank="Recruit"/>
      				</characters>
      			</guild>
      		</apiresponse>
      		
      		Class, race, and ranks needs to be loaded into a table first
      		*/
			
			$query = "SELECT class_name,class_id FROM #__raidplanner_class";
			$db->setQuery($query);
			$classes = $db->loadAssocList('class_name');
			
			$query = "SELECT race_name,race_id FROM #__raidplanner_race";
			$db->setQuery($query);
			$races = $db->loadAssocList('race_name');
			$ranks = RaidPlannerHelper::getRanks( true );

			foreach($xml_parser->document->guild[0]->characters[0]->character as $member)
			{
				// check if character exists
				$query = "SELECT character_id FROM #__raidplanner_character WHERE char_name LIKE BINARY ".$db->Quote($member->attributes('name'))."";
				$db->setQuery($query);
				$char_id = $db->loadResult();
				// not found insert it
				if (!$char_id) {
					$query="INSERT INTO #__raidplanner_character SET char_name=".$db->Quote($member->attributes('name'))."";
					$db->setQuery($query);
					$db->query();
					$char_id=$db->insertid();
				}
				
				$query = "UPDATE #__raidplanner_character SET class_id='".intval($classes[ $member->attributes('class') ])."'
															,race_id='".intval($races[ $member->attributes('race') ])."'
															,char_level='".intval($member->attributes('level'))."'
															,rank='".intval($ranks[$member->attributes('rank')])."'
															,guild_id='".intval($guild_id)."'
															WHERE character_id=".$char_id;
				$db->setQuery($query);
				$db->query();
			}

			/* delete all guildless characters */
			$query = "DELETE FROM #__raidplanner_character WHERE guild_id=0";
			$db->setQuery($query);
			$db->query();
			
			if ($showOkStatus)
			{
				JError::raiseNotice('0', 'LotroSync successed');
			}
		} else {
			JError::raiseWarning('100', 'LotroSync data doesn\'t match');
		}
	}

	public function onRPGetCharacterLink( $char_name )
	{
		return "#";
	}

	public function onRPGetGuildHeader()
	{
		return "<h2>" . $this->guild_name . "</h2>";
	}

	public function onRPLoadCSS()
	{
		$document = JFactory::getDocument();
		$document->addStyleSheet( 'media/com_raidplanner/css/raidplanner_lotro.css' );
	
		return true;
	}
}