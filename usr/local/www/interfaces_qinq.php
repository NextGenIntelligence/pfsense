:<?php
/* $Id$ */
/*
	interfaces_qinq.php
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
	Copyright (C) 2009 Ermal Luçi
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
	pfSense_BUILDER_BINARIES:	/usr/sbin/ngctl
	pfSense_MODULE: interfaces
*/

##|+PRIV
##|*IDENT=page-interfaces-qinq
##|*NAME=Interfaces: QinQ page
##|*DESCR=Allow access to the 'Interfaces: QinQ' page.
##|*MATCH=interfaces_qinq.php*
##|-PRIV

require("guiconfig.inc");
require_once("functions.inc");

if (!is_array($config['qinqs']['qinqentry']))
	$config['qinqs']['qinqentry'] = array();

$a_qinqs = &$config['qinqs']['qinqentry'];

function qinq_inuse($num) {
	global $config, $a_qinqs;

	$iflist = get_configured_interface_list(false, true);
	foreach ($iflist as $if) {
		if ($config['interfaces'][$if]['if'] == $a_qinqs[$num]['qinqif'])
			return true;
	}

	return false;
}

if ($_GET['act'] == "del") {
	$id = $_GET['id'];

	/* check if still in use */
	if (qinq_inuse($id)) {
		$input_errors[] = gettext("This QinQ cannot be deleted because it is still being used as an interface.");
	} elseif (empty($a_qinqs[$id]['vlanif']) || !does_interface_exist($a_qinqs[$id]['vlanif'])) {
		$input_errors[] = gettext("QinQ interface does not exist");
	} else {
		$qinq =& $a_qinqs[$id];

		$delmembers = explode(" ", $qinq['members']);
		if (count($delmembers) > 0) {
			foreach ($delmembers as $tag)
				mwexec("/usr/sbin/ngctl shutdown {$qinq['vlanif']}h{$tag}:");
		}
		mwexec("/usr/sbin/ngctl shutdown {$qinq['vlanif']}qinq:");
		mwexec("/usr/sbin/ngctl shutdown {$qinq['vlanif']}:");
		mwexec("/sbin/ifconfig {$qinq['vlanif']} destroy");
		unset($a_qinqs[$id]);

		write_config();

		header("Location: interfaces_qinq.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"),gettext("QinQ"));
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors)
	print_input_errors($input_errors);

$tab_array = array();
$tab_array[] = array(gettext("Interface assignments"), false, "interfaces_assign.php");
$tab_array[] = array(gettext("Interface Groups"), false, "interfaces_groups.php");
$tab_array[] = array(gettext("Wireless"), false, "interfaces_wireless.php");
$tab_array[] = array(gettext("VLANs"), false, "interfaces_vlan.php");
$tab_array[] = array(gettext("QinQs"), true, "interfaces_qinq.php");
$tab_array[] = array(gettext("PPPs"), false, "interfaces_ppps.php");
$tab_array[] = array(gettext("GRE"), false, "interfaces_gre.php");
$tab_array[] = array(gettext("GIF"), false, "interfaces_gif.php");
$tab_array[] = array(gettext("Bridges"), false, "interfaces_bridge.php");
$tab_array[] = array(gettext("LAGG"), false, "interfaces_lagg.php");
display_top_tabs($tab_array);

print_info_box(sprintf(gettext('Not all drivers/NICs support 802.1Q QinQ tagging properly. <br />On cards that do not explicitly support it, ' .
							   'QinQ tagging will still work, but the reduced MTU may cause problems.<br />' .
							   'See the %s handbook for information on supported cards.'), $g['product_name']));

?>
<div class="table-responsive">
	<table class="table table-striped table-hover table-condensed">
		<thead>
			<tr>
			  <th><?=gettext("Interface"); ?></th>
			  <th><?=gettext("Tag");?></td>
			  <th><?=gettext("QinQ members"); ?></th>
			  <th><?=gettext("Description"); ?></th>
			  <th></th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($a_qinqs as $i => $qinq):?>
			<tr>
				<td>
					<?=htmlspecialchars($qinq['if'])?>
				</td>
				<td>
					<?=htmlspecialchars($qinq['tag'])?>
				</td>
				<td>
<?php if (strlen($qinq['members']) > 20):?>
					<?=substr(htmlspecialchars($qinq['members']), 0, 20)?>&hellip;
<?php else:?>
					<?=htmlspecialchars($qinq['members'])?>
<?php endif; ?>
				</td>
				<td>
					<?=htmlspecialchars($qinq['descr'])?>&nbsp;
				</td>
				<td>
					<a href="interfaces_qinq_edit.php?id=<?=$i?>" class="btn btn-default btn-xs"><?=gettext("Edit")?></a>
					<a href="interfaces_qinq.php?act=del&amp;id=<?=$i?>" class="btn btn-danger btn-xs"><?=gettext("Delete")?></a>
				</td>
			</tr>
<?php
}
?>
		</tbody>
	</table>
</div>

<nav class="action-buttons">
	<a href="interfaces_qinq_edit.php" class="btn btn-success">
		<?=gettext("Add")?>
	</a>
</nav>
<?php
include("foot.inc");