<?php
/* $Id$ */
/*
	firewall_aliases_edit.php
	Copyright (C) 2004 Scott Ullrich
	Copyright (C) 2009 Ermal Luçi
	Copyright (C) 2010 Jim Pingle
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
	All rights reserved.

	originally part of m0n0wall (http://m0n0.ch/wall)
	Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>.
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/
/*
	pfSense_BUILDER_BINARIES:	/bin/rm	/bin/mkdir	/usr/bin/fetch
	pfSense_MODULE:	aliases
*/

##|+PRIV
##|*IDENT=page-firewall-alias-edit
##|*NAME=Firewall: Alias: Edit page
##|*DESCR=Allow access to the 'Firewall: Alias: Edit' page.
##|*MATCH=firewall_aliases_edit.php*
##|-PRIV

require("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");

$pgtitle = array(gettext("Firewall"),gettext("Aliases"),gettext("Edit"));

$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/firewall_aliases.php');

// Keywords not allowed in names
$reserved_keywords = array("all", "pass", "block", "out", "queue", "max", "min", "pptp", "pppoe", "L2TP", "OpenVPN", "IPsec");

// Add all Load balance names to reserved_keywords
if (is_array($config['load_balancer']['lbpool']))
	foreach ($config['load_balancer']['lbpool'] as $lbpool)
		$reserved_keywords[] = $lbpool['name'];

$reserved_ifs = get_configured_interface_list(false, true);
$reserved_keywords = array_merge($reserved_keywords, $reserved_ifs, $reserved_table_names);
$max_alias_addresses = 5000;

if (!is_array($config['aliases']['alias']))
	$config['aliases']['alias'] = array();
$a_aliases = &$config['aliases']['alias'];

$tab = $_REQUEST['tab'];

if($_POST)
	$origname = $_POST['origname'];

// Debugging
if($debug)
	unlink_if_exists("{$g['tmp_path']}/alias_rename_log.txt");

function alias_same_type($name, $type) {
	global $config;

	foreach ($config['aliases']['alias'] as $alias) {
		if ($name == $alias['name']) {
			if (in_array($type, array("host", "network")) &&
				in_array($alias['type'], array("host", "network")))
				return true;
			if ($type  == $alias['type'])
				return true;
			else
				return false;
		}
	}
	return true;
}

if (is_numericint($_GET['id']))
	$id = $_GET['id'];
if (isset($_POST['id']) && is_numericint($_POST['id']))
	$id = $_POST['id'];

if (isset($id) && $a_aliases[$id]) {
	$original_alias_name = $a_aliases[$id]['name'];
	$pconfig['name'] = $a_aliases[$id]['name'];
	$pconfig['detail'] = $a_aliases[$id]['detail'];
	$pconfig['address'] = $a_aliases[$id]['address'];
	$pconfig['type'] = $a_aliases[$id]['type'];
	$pconfig['descr'] = html_entity_decode($a_aliases[$id]['descr']);

	if(preg_match("/urltable/i", $a_aliases[$id]['type'])) {
		$pconfig['address'] = $a_aliases[$id]['url'];
		$pconfig['updatefreq'] = $a_aliases[$id]['updatefreq'];
	}
	if($a_aliases[$id]['aliasurl'] != "") {
		if(is_array($a_aliases[$id]['aliasurl']))
			$pconfig['address'] = implode(" ", $a_aliases[$id]['aliasurl']);
		else
			$pconfig['address'] = $a_aliases[$id]['aliasurl'];
	}
}

if ($_POST) {
	unset($input_errors);
	$vertical_bar_err_text = gettext("Vertical bars (|) at start or end, or double in the middle of descriptions not allowed. Descriptions have been cleaned. Check and save again.");

	/* input validation */

	$reqdfields = explode(" ", "name");
	$reqdfieldsn = array(gettext("Name"));

	do_input_validation($_POST, $reqdfields, $reqdfieldsn, $input_errors);

	$x = is_validaliasname($_POST['name']);
	if (!isset($x)) {
		$input_errors[] = gettext("Reserved word used for alias name.");
	} else if ($_POST['type'] == "port" && (getservbyname($_POST['name'], "tcp") || getservbyname($_POST['name'], "udp"))) {
		$input_errors[] = gettext("Reserved word used for alias name.");
	} else {
		if (is_validaliasname($_POST['name']) == false)
			$input_errors[] = gettext("The alias name must be less than 32 characters long, may not consist of only numbers, and may only contain the following characters") . " a-z, A-Z, 0-9, _.";
	}
	/* check for name conflicts */
	if (empty($a_aliases[$id])) {
		foreach ($a_aliases as $alias) {
			if ($alias['name'] == $_POST['name']) {
				$input_errors[] = gettext("An alias with this name already exists.");
				break;
			}
		}
	}

	/* Check for reserved keyword names */
	foreach($reserved_keywords as $rk)
		if($rk == $_POST['name'])
			$input_errors[] = sprintf(gettext("Cannot use a reserved keyword as alias name %s"), $rk);

	/* check for name interface description conflicts */
	foreach($config['interfaces'] as $interface) {
		if($interface['descr'] == $_POST['name']) {
			$input_errors[] = gettext("An interface description with this name already exists.");
			break;
		}
	}

	$alias = array();
	$address = array();
	$final_address_details = array();
	$alias['name'] = $_POST['name'];

	if (preg_match("/urltable/i", $_POST['type'])) {
		$address = "";

		/* item is a url table type */
		if ($_POST['address'][0]) {
			/* fetch down and add in */
			$_POST['address'][0] = trim($_POST['address'][0]);
			$address[] = $_POST['address'][0];
			$alias['url'] = $_POST['address'][0];
			$alias['updatefreq'] = $_POST['frequency'][0] ? $_POST['frequency'][0] : 7;
			if (!is_URL($alias['url']) || empty($alias['url'])) {
				$input_errors[] = gettext("You must provide a valid URL.");
			} elseif (! process_alias_urltable($alias['name'], $alias['url'], 0, true)) {
				$input_errors[] = gettext("Unable to fetch usable data.");
			}
			if ($_POST["detail"][0] != "") {
				if ((strpos($_POST["detail"][0], "||") === false) && (substr($_POST["detail"][0], 0, 1) != "|") && (substr($_POST["detail"][0], -1, 1) != "|")) {
					$final_address_details[] = $_POST["detail"][0];
				} else {
					/* Remove leading and trailing vertical bars and replace multiple vertical bars with single, */
					/* and put in the output array so the text is at least redisplayed for the user. */
					$final_address_details[] = preg_replace('/\|\|+/', '|', trim($_POST["detail"][0], "|"));
					$input_errors[] = $vertical_bar_err_text;
				}
			} else
				$final_address_details[] = sprintf(gettext("Entry added %s"), date('r'));
		}
	} else if ($_POST['type'] == "url" || $_POST['type'] == "url_ports") {
		$desc_fmt_err_found = false;

		/* item is a url type */
			foreach ($_POST['address'] as $idx => $post_address) {
				/* fetch down and add in */
				$temp_filename = tempnam("{$g['tmp_path']}/", "alias_import");
				unlink_if_exists($temp_filename);
				$verify_ssl = isset($config['system']['checkaliasesurlcert']);
				mkdir($temp_filename);
				download_file($post_address, $temp_filename . "/aliases", $verify_ssl);

				/* if the item is tar gzipped then extract */
				if(stristr($post_address, ".tgz"))
					process_alias_tgz($temp_filename);
				else if(stristr($post_address, ".zip"))
					process_alias_unzip($temp_filename);

				if (!isset($alias['aliasurl']))
					$alias['aliasurl'] = array();

				$alias['aliasurl'][] = $post_address;
				if ($_POST['detail'][$idx] != "") {
					if ((strpos($_POST['detail'][$idx], "||") === false) && (substr($_POST['detail'][$idx], 0, 1) != "|") && (substr($_POST['detail'][$idx], -1, 1) != "|")) {
						$final_address_details[] = $_POST['detail'][$idx];
					} else {
						/* Remove leading and trailing vertical bars and replace multiple vertical bars with single, */
						/* and put in the output array so the text is at least redisplayed for the user. */
						$final_address_details[] = preg_replace('/\|\|+/', '|', trim($_POST['detail'][$idx], "|"));
						if (!$desc_fmt_err_found) {
							$input_errors[] = $vertical_bar_err_text;
							$desc_fmt_err_found = true;
						}
					}
				} else
					$final_address_details[] = sprintf(gettext("Entry added %s"), date('r'));

				if(file_exists("{$temp_filename}/aliases")) {
					$address = parse_aliases_file("{$temp_filename}/aliases", $_POST['type'], 3000);
					if($address == null) {
						/* nothing was found */
						$input_errors[] = sprintf(gettext("You must provide a valid URL. Could not fetch usable data from '%s'."), $post_address);
					}
					mwexec("/bin/rm -rf " . escapeshellarg($temp_filename));
				} else {
					$input_errors[] = sprintf(gettext("URL '%s' is not valid."), $post_address);
				}
			}
		unset($desc_fmt_err_found);
		if ($_POST['type'] == "url_ports")
			$address = group_ports($address);
	} else {
		/* item is a normal alias type */
		$wrongaliases = "";
		$desc_fmt_err_found = false;
		$alias_address_count = 0;

		// First trim and expand the input data.
		// Users can paste strings like "10.1.2.0/24 10.3.0.0/16 9.10.11.0/24" into an address box.
		// They can also put an IP range.
		// This loop expands out that stuff so it can easily be validated.
			foreach ($_POST['address'] as $idx => $post_address) {
				if ($post_address != "") {
					if ((strpos($post_address, "||") === false) && (substr($post_address, 0, 1) != "|") && (substr($post_address, -1, 1) != "|")) {
						$detail_text = $post_address;
					} else {
						/* Remove leading and trailing vertical bars and replace multiple vertical bars with single, */
						/* and put in the output array so the text is at least redisplayed for the user. */
						$detail_text = preg_replace('/\|\|+/', '|', trim($post_address, "|"));
						if (!$desc_fmt_err_found) {
							$input_errors[] = $vertical_bar_err_text;
							$desc_fmt_err_found = true;
						}
					}
				} else {
					$detail_text = sprintf(gettext("Entry added %s"), date('r'));
				}
				$address_items = explode(" ", trim($post_address));
				foreach ($address_items as $address_item) {
					$iprange_type = is_iprange($address_item);
					if ($iprange_type == 4) {
						list($startip, $endip) = explode('-', $address_item);
						if ($_POST['type'] == "network") {
							// For network type aliases, expand an IPv4 range into an array of subnets.
							$rangesubnets = ip_range_to_subnet_array($startip, $endip);
							foreach ($rangesubnets as $rangesubnet) {
								if ($alias_address_count > $max_alias_addresses) {
									break;
								}
								list($address_part, $subnet_part) = explode("/", $rangesubnet);
								$input_addresses[] = $address_part;
								$input_address_subnet[] = $subnet_part;
								$final_address_details[] = $detail_text;
								$alias_address_count++;
							}
						} else {
							// For host type aliases, expand an IPv4 range into a list of individual IPv4 addresses.
							$rangeaddresses = ip_range_to_address_array($startip, $endip, $max_alias_addresses - $alias_address_count);
							if (is_array($rangeaddresses)) {
								foreach ($rangeaddresses as $rangeaddress) {
									$input_addresses[] = $rangeaddress;
									$input_address_subnet[] = "";
									$final_address_details[] = $detail_text;
									$alias_address_count++;
								}
							} else {
								$input_errors[] = sprintf(gettext('Range is too large to expand into individual host IP addresses (%s)'), $address_item);
								$input_errors[] = sprintf(gettext('The maximum number of entries in an alias is %s'), $max_alias_addresses);
								// Put the user-entered data in the output anyway, so it will be re-displayed for correction.
								$input_addresses[] = $address_item;
								$input_address_subnet[] = "";
								$final_address_details[] = $detail_text;
							}
						}
					} else if ($iprange_type == 6) {
						$input_errors[] = sprintf(gettext('IPv6 address ranges are not supported (%s)'), $address_item);
						// Put the user-entered data in the output anyway, so it will be re-displayed for correction.
						$input_addresses[] = $address_item;
						$input_address_subnet[] = "";
						$final_address_details[] = $detail_text;
					} else {
						$subnet_type = is_subnet($address_item);
						if (($_POST['type'] == "host") && $subnet_type) {
							if ($subnet_type == 4) {
								// For host type aliases, if the user enters an IPv4 subnet, expand it into a list of individual IPv4 addresses.
								if (subnet_size($address_item) <= ($max_alias_addresses - $alias_address_count)) {
									$rangeaddresses = subnetv4_expand($address_item);
									foreach ($rangeaddresses as $rangeaddress) {
										$input_addresses[] = $rangeaddress;
										$input_address_subnet[] = "";
										$final_address_details[] = $detail_text;
										$alias_address_count++;
									}
								} else {
									$input_errors[] = sprintf(gettext('Subnet is too large to expand into individual host IP addresses (%s)'), $address_item);
									$input_errors[] = sprintf(gettext('The maximum number of entries in an alias is %s'), $max_alias_addresses);
									// Put the user-entered data in the output anyway, so it will be re-displayed for correction.
									$input_addresses[] = $address_item;
									$input_address_subnet[] = "";
									$final_address_details[] = $detail_text;
								}
							} else {
								$input_errors[] = sprintf(gettext('IPv6 subnets are not supported in host aliases (%s)'), $address_item);
								// Put the user-entered data in the output anyway, so it will be re-displayed for correction.
								$input_addresses[] = $address_item;
								$input_address_subnet[] = "";
								$final_address_details[] = $detail_text;
							}
						} else {
							list($address_part, $subnet_part) = explode("/", $address_item);
							if (!empty($subnet_part)) {
								if (is_subnet($address_item)) {
									$input_addresses[] = $address_part;
									$input_address_subnet[] = $subnet_part;
								} else {
									// The user typed something like "1.2.3.444/24" or "1.2.3.0/36" or similar rubbish.
									// Feed it through without splitting it apart, then it will be caught by the validation loop below.
									$input_addresses[] = $address_item;
									$input_address_subnet[] = "";
								}
							} else {
								$input_addresses[] = $address_part;
								$input_address_subnet[] = $_POST["address_subnet"][$idx];
							}
							$final_address_details[] = $detail_text;
							$alias_address_count++;
						}
					}
					if ($alias_address_count > $max_alias_addresses) {
						$input_errors[] = sprintf(gettext('The maximum number of entries in an alias has been exceeded (%s)'), $max_alias_addresses);
						break;
					}
				}
			}

		// Validate the input data expanded above.
		foreach($input_addresses as $idx => $input_address) {
			if (is_alias($input_address)) {
				if (!alias_same_type($input_address, $_POST['type']))
					// But alias type network can include alias type urltable. Feature#1603.
					if (!($_POST['type'] == 'network' &&
						  preg_match("/urltable/i", alias_get_type($input_address))))
						$wrongaliases .= " " . $input_address;
			} else if ($_POST['type'] == "port") {
				if (!is_port($input_address) && !is_portrange($input_address))
					$input_errors[] = $input_address . " " . gettext("is not a valid port or alias.");
			} else if ($_POST['type'] == "host" || $_POST['type'] == "network") {
				if (is_subnet($input_address) ||
					(!is_ipaddr($input_address) && !is_hostname($input_address)))
					$input_errors[] = sprintf(gettext('%1$s is not a valid %2$s address, FQDN or alias.'), $input_address, $_POST['type']);
			}
			$tmpaddress = $input_address;
			if ($_POST['type'] != "host" && is_ipaddr($input_address) && $input_address_subnet[$idx] != "") {
				if (!is_subnet($input_address . "/" . $input_address_subnet[$idx]))
					$input_errors[] = sprintf(gettext('%s/%s is not a valid subnet.'), $input_address, $input_address_subnet[$idx]);
				else
					$tmpaddress .= "/" . $input_address_subnet[$idx];
			}
			$address[] = $tmpaddress;
		}
		unset($desc_fmt_err_found);
		if ($wrongaliases != "")
			$input_errors[] = sprintf(gettext('The alias(es): %s cannot be nested because they are not of the same type.'), $wrongaliases);
	}

	unset($vertical_bar_err_text);

	// Allow extending of the firewall edit page and include custom input validation
	pfSense_handle_custom_code("/usr/local/pkg/firewall_aliases_edit/input_validation");

	if (!$input_errors) {
		$alias['address'] = is_array($address) ? implode(" ", $address) : $address;
		$alias['descr'] = $_POST['descr'];
		$alias['type'] = $_POST['type'];
		$alias['detail'] = implode("||", $final_address_details);

		/*   Check to see if alias name needs to be
		 *   renamed on referenced rules and such
		 */
		if ($_POST['name'] != $_POST['origname']) {
			// Firewall rules
			update_alias_names_upon_change(array('filter', 'rule'), array('source', 'address'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('filter', 'rule'), array('destination', 'address'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('filter', 'rule'), array('source', 'port'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('filter', 'rule'), array('destination', 'port'), $_POST['name'], $origname);
			// NAT Rules
			update_alias_names_upon_change(array('nat', 'rule'), array('source', 'address'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'rule'), array('source', 'port'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'rule'), array('destination', 'address'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'rule'), array('destination', 'port'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'rule'), array('target'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'rule'), array('local-port'), $_POST['name'], $origname);
			// NAT 1:1 Rules
			//update_alias_names_upon_change(array('nat', 'onetoone'), array('external'), $_POST['name'], $origname);
			//update_alias_names_upon_change(array('nat', 'onetoone'), array('source', 'address'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'onetoone'), array('destination', 'address'), $_POST['name'], $origname);
			// NAT Outbound Rules
			update_alias_names_upon_change(array('nat', 'advancedoutbound', 'rule'), array('source', 'network'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'advancedoutbound', 'rule'), array('sourceport'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'advancedoutbound', 'rule'), array('destination', 'address'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'advancedoutbound', 'rule'), array('dstport'), $_POST['name'], $origname);
			update_alias_names_upon_change(array('nat', 'advancedoutbound', 'rule'), array('target'), $_POST['name'], $origname);
			// Alias in an alias
			update_alias_names_upon_change(array('aliases', 'alias'), array('address'), $_POST['name'], $origname);
		}

		pfSense_handle_custom_code("/usr/local/pkg/firewall_aliases_edit/pre_write_config");

		if (isset($id) && $a_aliases[$id]) {
			if ($a_aliases[$id]['name'] != $alias['name']) {
				foreach ($a_aliases as $aliasid => $aliasd) {
					if ($aliasd['address'] != "") {
						$tmpdirty = false;
						$tmpaddr = explode(" ", $aliasd['address']);
						foreach ($tmpaddr as $tmpidx => $tmpalias) {
							if ($tmpalias == $a_aliases[$id]['name']) {
								$tmpaddr[$tmpidx] = $alias['name'];
								$tmpdirty = true;
							}
						}
						if ($tmpdirty == true)
							$a_aliases[$aliasid]['address'] = implode(" ", $tmpaddr);
					}
				}
			}
			$a_aliases[$id] = $alias;
		} else
			$a_aliases[] = $alias;

		// Sort list
		$a_aliases = msort($a_aliases, "name");

		if (write_config())
			mark_subsystem_dirty('aliases');

		if(!empty($tab))
			header("Location: firewall_aliases.php?tab=" . htmlspecialchars ($tab));
		else
			header("Location: firewall_aliases.php");
		exit;
	}
	//we received input errors, copy data to prevent retype
	else
	{
		$pconfig['name'] = $_POST['name'];
		$pconfig['descr'] = $_POST['descr'];
		if (($_POST['type'] == 'url') || ($_POST['type'] == 'url_ports'))
			$pconfig['address'] = implode(" ", $alias['aliasurl']);
		else
			$pconfig['address'] = implode(" ", $address);
		$pconfig['type'] = $_POST['type'];
		$pconfig['detail'] = implode("||", $final_address_details);
	}
}

include("head.inc");

$network_str = gettext("Network or FQDN");
$networks_str = gettext("Network(s)");
$cidr_str = gettext("CIDR");
$description_str = gettext("Description");
$hosts_str = gettext("Host(s)");
$ip_str = gettext("IP or FQDN");
$ports_str = gettext("Port(s)");
$port_str = gettext("Port");
$url_str = gettext("URL (IPs)");
$url_ports_str = gettext("URL (Ports)");
$urltable_str = gettext("URL Table (IPs)");
$urltable_ports_str = gettext("URL Table (Ports)");
$update_freq_str = gettext("Update Freq. (days)");

$help = array(
	'network' => "Networks are specified in CIDR format.  Select the CIDR mask that pertains to each entry. /32 specifies a single IPv4 host, /128 specifies a single IPv6 host, /24 specifies 255.255.255.0, /64 specifies a normal IPv6 network, etc. Hostnames (FQDNs) may also be specified, using a /32 mask for IPv4 or /128 for IPv6. You may also enter an IP range such as 192.168.1.1-192.168.1.254 and a list of CIDR networks will be derived to fill the range.",
	'host' => "Enter as many hosts as you would like.  Hosts must be specified by their IP address or fully qualified domain name (FQDN). FQDN hostnames are periodically re-resolved and updated. If multiple IPs are returned by a DNS query, all are used. You may also enter an IP range such as 192.168.1.1-192.168.1.10 or a small subnet such as 192.168.1.16/28 and a list of individual IP addresses will be generated.",
	'port' => "Enter as many ports as you wish.  Port ranges can be expressed by separating with a colon.",
	'url' => "Enter as many URLs as you wish. After saving we will download the URL and import the items into the alias. Use only with small sets of IP addresses (less than 3000).",
	'url_ports' => "Enter as many URLs as you wish. After saving we will download the URL and import the items into the alias. Use only with small sets of Ports (less than 3000).",
	'urltable' => "Enter a single URL containing a large number of IPs and/or Subnets. After saving we will download the URL and create a table file containing these addresses. This will work with large numbers of addresses (30,000+) or small numbers.",
	'urltable_ports' => "Enter a single URL containing a list of Port numbers and/or Port ranges. After saving we will download the URL.",
);

$types = array(
	'host' => 'Host(s)',
	'network' => 'Network(s)',
	'port' => 'Port(s)',
	'url' => 'URL (IPs)',
	'url_ports' => 'URL (Ports)',
	'urltable' => 'URL Table (IPs)',
	'urltable_ports' => 'URL Table (Ports)',
);

if (empty($tab)) {
	if (preg_match("/url/i", $pconfig['type']))
		$tab = 'url';
	else if ($pconfig['type'] == 'host')
		$tab = 'ip';
	else
		$tab = $pconfig['type'];
}

if ($input_errors)
	print_input_errors($input_errors);

require('classes/Form.class.php');
$form = new Form;

$form->addGlobal(new Form_Input(
	'tab',
	null,
	'hidden',
	$tab
));
$form->addGlobal(new Form_Input(
	'origname',
	null,
	'hidden',
	$pconfig['name']
));

if (isset($id) && $a_aliases[$id])
{
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$section = new Form_Section('Properties');
$section->addInput(new Form_Input(
	'name',
	'Name',
	'text',
	$pconfig['name']
))->setPattern('[a-zA-Z0-9_]+')->setHelp('The name of the alias may only consist '.
	'of the characters "a-z, A-Z, 0-9 and _".');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('You may enter a description here for your reference (not parsed).');

$section->addInput(new Form_Select(
	'type',
	'Type',
	isset($pconfig['type']) ? $pconfig['type'] : $tab,
	$types
))->toggles();

$form->add($section);

foreach ($types as $type => $typeName)
{
	$section = new Form_Section('Details for '. $typeName);
	$section->addClass('toggle-'.$type.' collapse');

	// Texts are rather long; don't repeat for every input
	$section->addInput(new Form_StaticText('Help', $help[$type]));

	// Only include values for the correct type
	if (isset($pconfig['type']) && $type == $pconfig['type'])
	{
		$addresses = explode(' ', $pconfig['address']);
		$details = explode('||', $pconfig['detail']);
	}
	else
	{
		// When creating a new entry show at lease one input
		$addresses = array('');
		$details = array();
	}

	foreach ($addresses as $idx => $address)
	{
		$address_subnet = '';
		if (($pconfig['type'] != 'host') && is_subnet($address))
			list($address, $address_subnet) = explode('/', $address);

		if (substr($type, 0, 3) == 'url')
		{
			$group = new Form_Group('URL to download');

			$group->add(new Form_Input(
				'address',
				'URL to download',
				'url',
				$address
			));

			if (in_array($type, ['urltable', 'urltable_ports']))
			{
				$group->add(new Form_Input(
					'frequency',
					'Update frequency (days)',
					'number',
					$address_subnet,
					['min' => 1]
				));
			}
		}
		else
		{
			$group = new Form_Group('IP or FQDN');

			$group->add(new Form_IpAddress(
				'address',
				'IP or FQDN',
				$address
			))->addMask('address_subnet', $address_subnet);

			$group->add(new Form_Input(
				'detail',
				'Description (not parsed)',
				'text',
				$details[$idx]
			));
		}

		$group->enableDuplication();
		$section->add($group);
	}

	$form->add($section);
}

print $form;

include("foot.inc");
