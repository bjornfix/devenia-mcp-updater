<?php
/** Focused private transport/authority contract, using the existing public updater fixture. */
declare(strict_types=1);
require __DIR__.'/check-mcp-expose-update-policy-runtime.php';
function wp_tempnam(string $name) { return tempnam(sys_get_temp_dir(),'devenia-private-'); }
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
echo json_encode(['private_passed'=>$count,'failed'=>0])."\n";
