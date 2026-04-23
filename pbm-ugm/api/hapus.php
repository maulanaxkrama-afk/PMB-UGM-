<?php
include 'config/db.php';
session_start();
if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}
if(isset($_GET['id'])){
    $id = (int)$_GET['id'];
    mysqli_query($conn, "DELETE FROM pendaftar WHERE id=$id");
}
header("Location: dashboard.php");
exit;
?>
