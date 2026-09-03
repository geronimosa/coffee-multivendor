<?php
declare(strict_types=1);

function vendor_membership(PDO $pdo, int $userId, int $vendorId): ?array
{
    $stmt = $pdo->prepare('SELECT ru.role,u.name,u.email FROM restaurant_users ru JOIN users u ON u.id=ru.user_id WHERE ru.user_id=? AND ru.restaurant_id=? AND u.active=1 LIMIT 1');
    $stmt->execute([$userId, $vendorId]);
    return $stmt->fetch() ?: null;
}

function staff_can_access(PDO $pdo, int $vendorId): bool
{
    if (!empty($_SESSION['user_id'])) {
        $stmt=$pdo->prepare("SELECT role,active FROM users WHERE id=?");$stmt->execute([$_SESSION['user_id']]);$user=$stmt->fetch();
        if ($user && (int)$user['active'] && $user['role']==='super_admin') return true;
    }
    $keyId=(int)($_SESSION['staff_access_key_id']??0);
    if($keyId>0 && (int)($_SESSION['staff_key_vendor_id']??0)===$vendorId){
        $stmt=$pdo->prepare("SELECT id,portal_role FROM edge_staff_access_keys WHERE id=? AND vendor_id=? AND status='active' AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
        $stmt->execute([$keyId,$vendorId]);
        if($key=$stmt->fetch()){$_SESSION['staff_key_role']=(string)$key['portal_role'];return true;}
        unset($_SESSION['staff_access_key_id'],$_SESSION['staff_key_vendor_id'],$_SESSION['staff_key_username'],$_SESSION['staff_key_role']);
    }
    $userId=(int)($_SESSION['staff_user_id']??0);
    return $userId>0 && (int)($_SESSION['staff_vendor_id']??0)===$vendorId && vendor_membership($pdo,$userId,$vendorId)!==null;
}

function complete_staff_key_login(PDO $pdo,string $accessKey,int $vendorId): bool
{
    $accessKey=trim($accessKey);
    if(!preg_match('/^[A-HJ-NP-Za-km-z2-9]{10}$/',$accessKey))return false;
    $stmt=$pdo->prepare("SELECT id,username,portal_role FROM edge_staff_access_keys WHERE vendor_id=? AND key_hash=? AND status='active' AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
    $stmt->execute([$vendorId,hash('sha256',$accessKey)]);$key=$stmt->fetch();
    if(!$key)return false;
    session_regenerate_id(true);$_SESSION['staff_access_key_id']=(int)$key['id'];$_SESSION['staff_key_vendor_id']=$vendorId;$_SESSION['staff_key_username']=(string)$key['username'];$_SESSION['staff_key_role']=(string)$key['portal_role'];
    unset($_SESSION['staff_user_id'],$_SESSION['staff_vendor_id']);
    return true;
}

function vendor_admin_can_access(PDO $pdo, int $vendorId): bool
{
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT role,active FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user && (int) $user['active'] && $user['role'] === 'super_admin') {
            return true;
        }
    }

    if((int)($_SESSION['staff_access_key_id']??0)>0&&(int)($_SESSION['staff_key_vendor_id']??0)===$vendorId&&($_SESSION['staff_key_role']??'')==='vendor_admin'){
        $stmt=$pdo->prepare("SELECT id FROM edge_staff_access_keys WHERE id=? AND vendor_id=? AND portal_role='vendor_admin' AND status='active' AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
        $stmt->execute([(int)$_SESSION['staff_access_key_id'],$vendorId]);if($stmt->fetchColumn())return true;
    }

    $userId = (int) ($_SESSION['staff_user_id'] ?? 0);
    if ($userId < 1 || (int) ($_SESSION['staff_vendor_id'] ?? 0) !== $vendorId) {
        return false;
    }
    $membership = vendor_membership($pdo, $userId, $vendorId);
    return $membership !== null && $membership['role'] === 'admin';
}

function require_vendor_admin_access(PDO $pdo, int $vendorId, string $vendorSlug): void
{
    if (vendor_admin_can_access($pdo, $vendorId)) {
        return;
    }
    if (empty($_SESSION['user_id']) && empty($_SESSION['staff_user_id'])) {
        redirect('/vendor/' . rawurlencode($vendorSlug));
    }
    http_response_code(403);
    exit('Vendor administrator access required.');
}

function complete_staff_token_login(PDO $pdo, string $token, int $vendorId): bool
{
    $stmt=$pdo->prepare('SELECT lt.id,lt.user_id FROM login_tokens lt WHERE lt.token=? AND lt.used=0 AND lt.expires_at>NOW() LIMIT 1 FOR UPDATE');
    $pdo->beginTransaction();
    try{$stmt->execute([$token]);$row=$stmt->fetch();if(!$row||!vendor_membership($pdo,(int)$row['user_id'],$vendorId)){$pdo->rollBack();return false;}
        $pdo->prepare('UPDATE login_tokens SET used=1 WHERE id=?')->execute([$row['id']]);$pdo->commit();session_regenerate_id(true);$_SESSION['staff_user_id']=(int)$row['user_id'];$_SESSION['staff_vendor_id']=$vendorId;return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
