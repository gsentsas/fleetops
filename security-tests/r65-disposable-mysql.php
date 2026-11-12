<?php
declare(strict_types=1);
function assertGate(bool $v,string $name):void {if(!$v)throw new RuntimeException($name);echo "R65_MYSQL_".$name."=PASS\n";}
$dsn='mysql:host=127.0.0.1;port=13365;dbname=synthetic_r65;charset=utf8mb4';
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db2=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE IF NOT EXISTS synthetic_token_claims (token_hash CHAR(64) PRIMARY KEY, company_uuid VARCHAR(36) NOT NULL, issuer_user_uuid VARCHAR(36) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL) ENGINE=InnoDB');
$db->exec('TRUNCATE TABLE synthetic_token_claims');
$secret=str_repeat('e',64);
$hash=hash('sha256',$secret);
$insert=$db->prepare('INSERT INTO synthetic_token_claims(token_hash,company_uuid,issuer_user_uuid,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))');
$insert->execute([$hash,'company-B','admin-B']);
assertGate($db->query('SELECT COUNT(*) FROM synthetic_token_claims')->fetchColumn()==1,'synthetic_schema_ready');
function redeem(PDO $db,string $secret,string $company):bool {
  $db->beginTransaction();
  try {
    $q=$db->prepare('SELECT company_uuid,issuer_user_uuid,expires_at,consumed_at FROM synthetic_token_claims WHERE token_hash=? FOR UPDATE');
    $q->execute([hash('sha256',$secret)]);
    $row=$q->fetch();
    if(!$row || $row['company_uuid']!==$company || $row['consumed_at']!==null || strtotime($row['expires_at'])<=time()){
       $db->rollBack();return false;
    }
    $upd=$db->prepare('UPDATE synthetic_token_claims SET consumed_at=UTC_TIMESTAMP() WHERE token_hash=? AND consumed_at IS NULL');
    $upd->execute([hash('sha256',$secret)]);
    if($upd->rowCount()!==1){$db->rollBack();return false;}
    $db->commit();return true;
  } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
assertGate(!redeem($db,$secret,'company-A'),'cross_tenant_refused');
assertGate(redeem($db,$secret,'company-B'),'first_redeem_succeeds');
assertGate(!redeem($db2,$secret,'company-B'),'second_connection_replay_refused');
assertGate(!redeem($db2,str_repeat('f',64),'company-B'),'forged_secret_refused');
$secret2=str_repeat('a',64);
$insert->execute([hash('sha256',$secret2),'company-B','admin-B']);
$db->prepare('UPDATE synthetic_token_claims SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256',$secret2)]);
assertGate(!redeem($db2,$secret2,'company-B'),'expired_refused');
$secret3=str_repeat('c',64);
$insert->execute([hash('sha256',$secret3),'company-B','admin-B']);
$db->beginTransaction();
$q=$db->prepare('SELECT token_hash FROM synthetic_token_claims WHERE token_hash=? FOR UPDATE');
$q->execute([hash('sha256',$secret3)]);
$db->rollBack();
assertGate(redeem($db2,$secret3,'company-B'),'rollback_does_not_consume');
echo "R65_MYSQL_SYNTHETIC_TRANSACTION_GATES=7/7\n";
