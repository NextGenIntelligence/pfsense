<?php
/* $Id$ */
/*
	pkg_mgr_installed.php
	Copyright (C) 2013-2015 Electric Sheep Fencing, LP
	Copyright (C) 2004-2012 Scott Ullrich <sullrich@gmail.com>
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
	pfSense_MODULE:	pkgs
*/

##|+PRIV
##|*IDENT=page-system-packagemanager-installed
##|*NAME=System: Package Manager: Installed page
##|*DESCR=Allow access to the 'System: Package Manager: Installed' page.
##|*MATCH=pkg_mgr_installed.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pkg-utils.inc");

$timezone = $config['system']['timezone'];
if (!$timezone)
	$timezone = "Etc/UTC";

date_default_timezone_set($timezone);

/* if upgrade in progress, alert user */
if(is_subsystem_dirty('packagelock')) {
	$pgtitle = array(gettext("System"),gettext("Package Manager"));
	include("head.inc");
	print_info_box_np("Please wait while packages are reinstalled in the background.");
	include("foot.inc");
	exit;
}

if(is_array($config['installedpackages']['package'])) {
	foreach($config['installedpackages']['package'] as $instpkg) {
		$tocheck[] = $instpkg['name'];
	}
	$currentvers = get_pkg_info($tocheck, array('version', 'xmlver', 'pkginfolink','descr'));
}
$closehead = false;
$pgtitle = array(gettext("System"),gettext("Package Manager"));
include("head.inc");

/* Print package server mismatch warning. See https://redmine.pfsense.org/issues/484 */
if (!verify_all_package_servers())
	print_info_box(package_server_mismatch_message());

/* Print package server SSL warning. See https://redmine.pfsense.org/issues/484 */
if (check_package_server_ssl() === false)
	print_info_box(package_server_ssl_failure_message());

$version = file_get_contents("/etc/version");
$tab_array = array();
$tab_array[] = array(gettext("Available Packages"), false, "pkg_mgr.php");
//	$tab_array[] = array("{$version} " . gettext("packages"), false, "pkg_mgr.php");
//	$tab_array[] = array("Packages for any platform", false, "pkg_mgr.php?ver=none");
//	$tab_array[] = array("Packages for a different platform", $requested_version == "other" ? true : false, "pkg_mgr.php?ver=other");
$tab_array[] = array(gettext("Installed Packages"), true, "pkg_mgr_installed.php");
display_top_tabs($tab_array);

if(!is_array($config['installedpackages']['package'])):?>
	<div class="alert alert-warning">
		<?=gettext("There are no packages currently installed.")?>
	</div>
<?php else: ?>
	<div class="table-responsive">
	<table class="table table-striped table-hover">
	<thead>
		<tr>
			<th><span class="sr-only"><?=gettext("Status")?></span></th>
			<th><?=gettext("Name")?></th>
			<th><?=gettext("Category")?></th>
			<th><?=gettext("Version")?></th>
			<th><?=gettext("Description")?></th>
		</tr>
	</thead>
	<tbody>
<?php
	$instpkgs = array();
	foreach($config['installedpackages']['package'] as $instpkg) {
		$instpkgs[] = $instpkg['name'];
	}
	natcasesort($instpkgs);

	foreach ($instpkgs as $index => $pkgname):
		$pkg = $config['installedpackages']['package'][$index];
		if(!$pkg['name'])
			continue;

		// get history/changelog git dir
		$commit_dir=explode("/",$pkg['config_file']);
		$changeloglink ="https://github.com/pfsense/pfsense-packages/commits/master/config/".$commit_dir[(count($commit_dir)-2)];
		#check package version
		$latest_package = $currentvers[$pkg['name']]['version'];
		if ($latest_package) {
			// we're running a newer version of the package
			if(strcmp($pkg['version'], $latest_package) > 0) {
				$status = 'Newer then available ('. $latest_package .')';
				$statusicon = 'exclamation';
			}
			// we're running an older version of the package
			if(strcmp($pkg['version'], $latest_package) < 0) {
				$status = 'Upgrade available to '.$latest_package;
				$statusicon = 'plus';
			}
			// we're running the current version
			if(!strcmp($pkg['version'], $latest_package)) {
				$status = 'Up-to-date';
				$statusicon = 'ok';
			}
			$pkgdescr = $currentvers[$pkg['name']]['descr'];
		} else {
			// unknown available package version
			$status = 'Unknown';
			$statusicon = 'question';
			$pkgdescr = $pkg['descr'];
		}
?>
	<tr>
		<td>
			<i title="<?=$status?>" class="icon icon-<?=$statusicon?>-sign"></i>
		</td>
		<td>
			<?=$pkg['name']?>
		</td>
		<td>
			<?=$pkg['category']?>
		</td>
		<td>
<?php if (!$g['disablepackagehistory']):?>
			<a target="_blank" title="<?=gettext("View changelog")?>" href="<?=htmlspecialchars($changeloglink)?>">
<?php endif;?>
				<?=htmlspecialchars($pkg['version'])?>
			</a>
		</td>
		<td>
			<?=$pkgdescr?>
		</td>
		<td>
			<a href="pkg_mgr_install.php?mode=delete&amp;pkg=<?=$pkg['name']?>" class="btn btn-danger">remove</a>
			<a href="pkg_mgr_install.php?mode=reinstallpkg&amp;pkg=<?=$pkg['name']?>" class="btn btn-info">reinstall</a>
			<a href="pkg_mgr_install.php?mode=reinstallxml&amp;pkg=<?=$pkg['name']?>" class="btn btn-info"><?=gettext("reinstall GUI")?></a>
<?php if(!$g['disablepackageinfo'] && $pkg['pkginfolink'] && $pkg['pkginfolink'] != $pkg['website']):?>
			<a target="_blank" title="<?=gettext("View more inforation")?>" href="<?=htmlspecialchars($pkg['pkginfolink'])?>" class="btn btn-default">info</a>
<?php endif;?>
		</td>
	</tr>
<?php endforeach;?>
	</tbody>
</table>
</div>
<?php endif; ?>
<?php include("foot.inc")?>