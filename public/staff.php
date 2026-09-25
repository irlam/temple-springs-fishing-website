<?php
require dirname(__DIR__).'/app/web.php';
$error='';$bailiffPortal=$bailiffPortal??false;
if(post()) {
    checkCsrf();
    if(field('action')==='logout') { $_SESSION=[]; session_regenerate_id(true); redirect('/staff.php'); }
    try {
        $s->rate('login-ip:'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR'],$config['app_key']),30,900);
        $s->rate('login-user:'.hash('sha256',strtolower(field('email'))),10,900);
        $u=$s->one('SELECT * FROM users WHERE email=? AND active=1',[strtolower(field('email'))]);
        $hash=$u['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if(!password_verify(is_string($_POST['password']??null)?$_POST['password']:'',$hash) || !$u || ($bailiffPortal && $u['role']!=='bailiff')) throw new RuntimeException('Email or password not recognised.');
        $returnToken=$_SESSION['scan_after_login']??null;
        session_regenerate_id(true); $_SESSION=['user'=>$u['id'],'auth_stamp'=>(new Temple\StaffAccounts($s))->stamp($u,$config['app_key']),'seen'=>time(),'login_at'=>time(),'csrf'=>bin2hex(random_bytes(32))];
        $s->audit($u['id'],'login'); redirect($returnToken?'/scan.php?token='.$returnToken:'/dashboard.php');
    } catch(RuntimeException $e) { $error=$e->getMessage(); }
}
if(isset($_SESSION['user'])) { staff();redirect('/dashboard.php'); }
head($bailiffPortal?'Bailiff sign in':'Staff sign in'); if($error) notice($error);
echo '<form method="post" class="panel narrow">'.csrf().'<label>Email<input type="email" name="email" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="button">Sign in securely</button></form><p class="quiet">Contact the fishery administrator for account creation or password resets.</p>';
echo '<p><a class="text-link" href="'.($bailiffPortal?'/staff.php':'/bailiff.php').'">'.($bailiffPortal?'Administrator sign in':'Bailiff sign in').'</a></p>';
foot();
