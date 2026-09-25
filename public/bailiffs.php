<?php
require dirname(__DIR__).'/app/web.php';
$u=staff(true);$accounts=new Temple\StaffAccounts($s);$error='';
$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
if($id===false) {http_response_code(404);exit('Bailiff not found.');}
if(post()) {
    checkCsrf();
    try {
        $id=filter_var(field('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($id===false || field('action')!=='save' || !in_array(field('active'),['0','1'],true)) throw new RuntimeException('Invalid account request.');
        // Preserve spaces in passwords; never render them back into the form.
        $password=is_string($_POST['password']??null)?$_POST['password']:'';
        $saved=$accounts->saveBailiff((int)$u['id'],$id,field('name'),field('email'),$password,field('active')==='1');
        $_SESSION['account_notice']='Bailiff saved. Their existing sessions have been signed out.';
        redirect('/bailiffs.php?id='.$saved);
    } catch(PDOException $e) { $error='Could not save this account. Please try again.'; }
    catch(RuntimeException $e) { $error=$e->getMessage(); }
}
$edit=$id?$s->one("SELECT id,name,email,active FROM users WHERE id=? AND role='bailiff'",[$id]):null;
if($id && !$edit) {http_response_code(404);exit('Bailiff not found.');}
head('Manage bailiffs');
echo '<div class="toolbar"><a class="button secondary" href="/dashboard.php">Back to dashboard</a><a class="button" href="/bailiffs.php">Add bailiff</a></div>';
if($error) notice($error);
if(isset($_SESSION['account_notice'])) {notice($_SESSION['account_notice']);unset($_SESSION['account_notice']);}
echo '<p>Each bailiff has their own email and password. They can look up bookings, scan tickets and confirm check-ins. Fishery settings, exports and account management remain admin-only.</p><p>Share the bailiff login address: <a class="text-link" href="/bailiff.php">'.h(rtrim($config['base_url'],'/').'/bailiff.php').'</a>. Give the password privately; no account email is sent.</p>';
$name=$error?field('name'):($edit['name']??'');$email=$error?field('email'):($edit['email']??'');$active=$error?field('active'):($edit['active']??1);
echo '<form method="post" class="panel narrow">'.csrf().'<h2>'.($edit?'Edit bailiff':'Add bailiff').'</h2><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.(int)$id.'"><label>Full name<input name="name" maxlength="100" required value="'.h($name).'" autocomplete="off"></label><label>Email address<input type="email" name="email" maxlength="254" required value="'.h($email).'" autocomplete="off"></label><label>'.($edit?'New password (leave blank to keep current password)':'Password').'<input type="password" name="password" minlength="14" maxlength="72" autocomplete="new-password" '.($edit?'':'required').'></label><p class="quiet">Use 14–72 characters (up to 72 bytes). Share it privately with the bailiff.</p><label>Account status<select name="active"><option value="1" '.((string)$active==='1'?'selected':'').'>Active</option><option value="0" '.((string)$active==='0'?'selected':'').'>Disabled</option></select></label><p class="quiet">Saving signs this bailiff out on every device. Disabling blocks login and retains their check-in history.</p><button class="button">Save bailiff</button></form>';
$rows=$s->all("SELECT id,name,email,active FROM users WHERE role='bailiff' ORDER BY active DESC,name,id");
echo '<h2>Bailiff accounts</h2>';
if(!$rows) notice('No bailiffs yet. Add your first account above.');
foreach($rows as $row) echo '<article class="panel"><div class="booking-title"><h3>'.h($row['name']).'</h3><span class="pill">'.($row['active']?'Active':'Disabled').'</span></div><p>'.h($row['email']).'</p><a class="button secondary" href="/bailiffs.php?id='.$row['id'].'">Edit bailiff</a></article>';
foot();
