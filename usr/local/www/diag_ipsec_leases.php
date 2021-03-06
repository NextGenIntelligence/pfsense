<?php
/* $Id$ */
/*
	diag_ipsec_leases.php
	Copyright (C) 2014 Ermal LUÇi
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
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
	pfSense_BUILDER_BINARIES:	/usr/local/sbin/ipsec
	pfSense_MODULE: ipsec
*/

##|+PRIV
##|*IDENT=page-status-ipsec-leases
##|*NAME=Status: IPsec: Leases page
##|*DESCR=Allow access to the 'Status: IPsec: Leases' page.
##|*MATCH=diag_ipsec_leases.php*
##|-PRIV

define(DEBUG, true); // Force dummy data for testing. Setting up a pFSense box to get real data is far too hard!

require("guiconfig.inc");
require("ipsec.inc");

$pgtitle = array(gettext("Status"),gettext("IPsec"),gettext("Leases"));
$shortcut_section = "ipsec";
include("head.inc");

$mobile = ipsec_dump_mobile();

$tab_array = array();
$tab_array[] = array(gettext("Overview"), false, "diag_ipsec.php");
$tab_array[] = array(gettext("Leases"), true, "diag_ipsec_leases.php");
$tab_array[] = array(gettext("SAD"), false, "diag_ipsec_sad.php");
$tab_array[] = array(gettext("SPD"), false, "diag_ipsec_spd.php");
$tab_array[] = array(gettext("Logs"), false, "diag_logs.php?logfile=ipsec");
display_top_tabs($tab_array);

if (isset($mobile['pool']) && is_array($mobile['pool'])) {
?>
	<div class="table-responsive">
		<table class="table table-hover table-condensed">
			<thead>
				<tr>
					<th><?=gettext("Pool")?></th>
					<th><?=gettext("Usage")?></th>
					<th><?=gettext("Online")?></th>
					<th><?=gettext("ID")?></th>
					<th><?=gettext("Host")?></th>
					<th><?=gettext("Status")?></th>
				</tr>
			</thead>
			<tbody>
<?php
			foreach($mobile['pool'] as $pool) {
				// The first row of each pool includes the pool information
?>
				<tr>
					<td>
						<?=$pool['name']?>
					</td>
					<td>
						<?=$pool['usage']?>
					</td>
					<td>
						<?=$pool['online']?>
					</td>

<?php
				$leaserow = true;
				if (is_array($pool['lease']) && count($pool['lease']) > 0) {
					foreach ($pool['lease'] as $lease) {
						if(!$leaserow) {
							// On subsequent rows the first three columns are blank
?>
				<tr>
					<td></td>
					<td></td>
					<td></td>
<?php
						}
						$leaserow = false;
?>
					<td>
						<?=htmlspecialchars($lease['id'])?>
					</td>
					<td>
						<?=htmlspecialchars($lease['host'])?>
					</td>
					<td>
						<?=htmlspecialchars($lease['status'])?>
					</td>
				</tr>
<?php

					}
				}
				else {
?>
					<td colspan="3" class="warning"><?=gettext('No leases from this pool yet.')?></td>
				</tr>
<?php
				}
			}
?>
			</tbody>
		</table>
	</div>
<?php
}
else
	print_info_box(gettext('No IPsec pools.'));

print_info_box(gettext('You can configure your IPsec subsystem by clicking ') . '<a href="vpn_ipsec.php">' . gettext("here.") . '</a>');

include("foot.inc");
