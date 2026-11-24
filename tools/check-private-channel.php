<?php
/** Focused private transport/authority contract, using the existing public updater fixture. */
declare(strict_types=1);
require __DIR__.'/check-mcp-expose-update-policy-runtime.php';
function wp_tempnam(string $name) { return tempnam(sys_get_temp_dir(),'devenia-private-'); }
class WP_Upgrader {
 public function __construct($skin) {}
 public function download_package($url,...$args){return devenia_mcp_updater_verify_download(false,$url);}
}
$GLOBALS['fixture_home_origin']='https://example.com';
define('DEVENIA_MCP_UPDATER_PRIVATE_TOKEN',bin2hex(random_bytes(32)));
define('DEVENIA_MCP_UPDATER_PRIVATE_SITE','https://example.com');
define('DEVENIA_MCP_UPDATER_PRIVATE_PLUGINS',['private-fixture/private-fixture.php']);
$private=$schema_3_git_entry;
$private['slug']='private-fixture';$private['file']='private-fixture/private-fixture.php';
$private['package']=devenia_mcp_updater_private_package_url($private['slug'],$private['sha256']);
$private['releaseIdentity']['slug']=$private['slug'];$private['releaseIdentity']['mainFile']=$private['file'];
$private['releaseIdentity']['members'][0]['path']=$private['file'];
$private['releaseIdentity']['membersSha256']=hash('sha256',json_encode($private['releaseIdentity']['members'],JSON_UNESCAPED_SLASHES));
$sign=static function($payload){$bytes=json_encode($payload);return ['schemaVersion'=>2,'signedPayload'=>base64_encode($bytes),'signature'=>['algorithm'=>'Ed25519','keyId'=>'fixture','value'=>base64_encode(sodium_crypto_sign_detached($bytes,sodium_crypto_sign_secretkey($GLOBALS['fixture_signing_keypair'])))]];};
$payload=['channel'=>'private','site'=>'https://example.com','plugins'=>[$private]];
$privateEnvelope=$sign($payload);$responseCode=200;$downloadBody=$upload_bytes;$requests=[];
$GLOBALS['fixture_manifest']=fixture_signed_manifest([$schema_3_git_entry]);
$GLOBALS['fixture_private_transport']=static function($url,$args)use(&$privateEnvelope,&$responseCode,&$downloadBody,&$requests){$requests[]=[$url,$args];if(isset($args['filename']))file_put_contents($args['filename'],$downloadBody);return ['response'=>['code'=>$responseCode],'body'=>json_encode($privateEnvelope)];};
$count=0;$assert=static function($ok,$name)use(&$count){$count++;if(!$ok)throw new RuntimeException($name);};
$flags=$GLOBALS['fixture_options']['auto_update_plugins']??null;
$assert(count(devenia_mcp_updater_private_plugins())===1,'valid private authority');
$assert(count(devenia_mcp_updater_manifest_plugins())===2,'private and public sources coexist');
$assert(($GLOBALS['fixture_options']['auto_update_plugins']??null)===$flags,'native flags preserved');
$first=$requests[0];$assert($first[1]['headers']['Authorization']==='Bearer '.DEVENIA_MCP_UPDATER_PRIVATE_TOKEN&&$first[1]['headers']['X-Devenia-Site']==='https://example.com'&&$first[1]['redirection']===0,'credentials are headers without redirects');
$assert(!str_contains($first[0],DEVENIA_MCP_UPDATER_PRIVATE_TOKEN),'no token in URL');
$GLOBALS['fixture_home_origin']='https://wrong.example';$assert(devenia_mcp_updater_private_plugins()===[],'another site is not enrolled');$GLOBALS['fixture_home_origin']='https://example.com';
$responseCode=404;$assert(devenia_mcp_updater_private_plugins()===[]&&count(devenia_mcp_updater_manifest_plugins())===1,'revoked private access preserves public updater');$responseCode=200;
$bad=$payload;$bad['site']='https://wrong.example';$privateEnvelope=$sign($bad);$assert(devenia_mcp_updater_private_plugins()===[],'signed wrong site rejected');
$privateEnvelope=$sign($payload);$privateEnvelope['signature']['value']=base64_encode(str_repeat('x',64));$assert(devenia_mcp_updater_private_plugins()===[],'wrong signature rejected');
$bad=$payload;$bad['plugins'][0]['file']='other/other.php';$privateEnvelope=$sign($bad);$assert(devenia_mcp_updater_private_plugins()===[],'wrong plugin rejected');
$privateEnvelope=$sign($payload);$file=devenia_mcp_updater_verify_download(false,$private['package']);$assert(is_string($file)&&hash_file('sha256',$file)===$private['sha256'],'ordinary upgrader hook downloads verified private package');wp_delete_file($file);
$downloadBody='tampered';$result=devenia_mcp_updater_verify_download(false,$private['package']);$assert(is_wp_error($result),'wrong hash rejected');
$last=end($requests);$assert(!file_exists($last[1]['filename']),'bad package removed');
$privateEnvelope=$sign($payload);$responseCode=403;$assert(is_wp_error(devenia_mcp_updater_private_download($private)),'revoked package rejected');
$report=devenia_mcp_updater_verify_private_sources();$assert($report['status']==='failed'&&count($report['missingFiles'])===1,'empty discovery is not a passing download verification');
$responseCode=200;$downloadBody=$upload_bytes;$report=devenia_mcp_updater_verify_private_sources();$assert($report['status']==='passed'&&count($report['results'])===1&&$report['results'][0]['bytes']===strlen($upload_bytes),'same qualified version can be verified without installation');
$self=['plugin'=>'devenia-mcp-updater/devenia-mcp-updater.php','rolloutId'=>'fixture-private-self','newVersion'=>DEVENIA_MCP_UPDATER_VERSION,'status'=>'passed','unexpectedSecret'=>'not-for-display'];
$GLOBALS['fixture_options'][DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION]=[$self];
$assert(devenia_mcp_updater_private_self_receipt()===null,'mutable list alone is not terminal evidence');
$GLOBALS['fixture_options'][devenia_mcp_updater_rollout_receipt_key($self['rolloutId'])]=$self;
$selfResult=devenia_mcp_updater_private_self_receipt();
$assert($selfResult['status']==='passed'&&!isset($selfResult['unexpectedSecret']),'only immutable safe terminal result is displayed');
// Feed the same incoming offers to the updater callback that WordPress registers.
$private_file = $private['file'];
$other_file = 'public-fixture/public-fixture.php';
$sibling_file = $private['slug'] . '/other.php';
$native_offers = array();
foreach ( array( $private_file, $other_file, $sibling_file ) as $plugin_file ) {
	$GLOBALS['fixture_installed'][ $plugin_file ] = array( 'Version' => '1.0.0' );
	$native_offers[ $plugin_file ] = (object) array(
		'plugin' => $plugin_file,
		'package' => 'https://downloads.wordpress.org/plugin/' . dirname( $plugin_file ) . '.zip',
		'new_version' => '9.0.0',
	);
}
$GLOBALS['fixture_installed'][ $private_file ]['Version'] = '0';
$update_plugins = static function () use ( $native_offers ) {
	$transient = (object) array( 'response' => $native_offers, 'no_update' => $native_offers );
	foreach ( $GLOBALS['fixture_filters']['pre_set_site_transient_update_plugins'] as $registration ) {
		$transient = $registration[1]( $transient );
	}
	return $transient;
};

$updates = $update_plugins();
$assert( $updates->response[ $private_file ]->package === $private['package'], 'a signed private offer replaces the native public offer' );
$assert( $updates->response[ $other_file ] === $native_offers[ $other_file ], 'the normal public offer survives alongside a private offer' );

$without_private_offer = $payload;
$without_private_offer['plugins'] = array();
$bad_signature = $sign( $payload );
$bad_signature['signature']['value'] = base64_encode( str_repeat( 'x', 64 ) );
foreach ( array(
	'missing offer' => array( 200, $sign( $without_private_offer ) ),
	'rejected signature' => array( 200, $bad_signature ),
	'revoked grant' => array( 404, $sign( $payload ) ),
) as $scenario => list( $responseCode, $privateEnvelope ) ) {
	$updates = $update_plugins();
	$assert( ! isset( $updates->response[ $private_file ] ) && ! isset( $updates->no_update[ $private_file ] ),
		'offers for the configured plugin file are removed after ' . $scenario );
	$assert( $updates->response[ $other_file ] === $native_offers[ $other_file ] &&
		$updates->response[ $sibling_file ] === $native_offers[ $sibling_file ],
		'public offers for other plugin files survive ' . $scenario );
}

$responseCode = 200;
$privateEnvelope = $sign( $payload );
$GLOBALS['fixture_installed'][ $private_file ]['Version'] = $private['version'];
$updates = $update_plugins();
$assert( ! isset( $updates->response[ $private_file ] ) &&
	$updates->no_update[ $private_file ]->package === $private['package'],
	'a current private version does not expose a public update' );

$public_copy = $private;
$public_copy['package'] = sprintf( 'https://downloads.devenia.com/artifacts/%1$s/%2$s/%1$s.zip', $private['slug'], $private['sha256'] );
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( array( $schema_3_git_entry, $public_copy ) );
$GLOBALS['fixture_installed'][ $private_file ]['Version'] = '0';
$updates = $update_plugins();
$assert( $updates->response[ $private_file ]->package === $private['package'], 'a signed public entry for the same file cannot displace the private offer' );
$privateEnvelope = $sign( $without_private_offer );
$updates = $update_plugins();
$assert( ! isset( $updates->response[ $private_file ] ), 'a signed public entry cannot fill an opted-in private file when its offer is absent' );

$GLOBALS['fixture_home_origin'] = 'https://wrong.example';
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( array( $schema_3_git_entry ) );
$updates = $update_plugins();
$assert( $updates->response[ $private_file ] === $native_offers[ $private_file ], 'a different site keeps its native public offer' );

echo json_encode(['private_passed'=>$count,'failed'=>0])."\n";
