<?php

  require_once('db.php');
  require_once('../model/Response.php');

  try {

    $writeDb = DB::connectWriteDB();

  } catch (PDOException $ex) {
    error_log("Database Connection error -" . $ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit;
  }

  if (array_key_exists("sessionid", $_GET)) {

    $sessionid = $_GET['sessionid'];

    if ($sessionid === '' || !is_numeric($sessionid)) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      ($sessionid === '' ? $response->addMessage('Session id cannot be blank') : false);
      (!is_numeric($sessionid) ? $response->addMessage('Session id should be numeric') : false);
      $response->send();
      exit;
    }

    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage('Access token is missing from header') : false);
      (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage('Access token cannot be blank') : false);
      $response->send();
      exit;
    }

    $accessToken = $_SERVER['HTTP_AUTHORIZATION'];

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

      try {
        $query = $writeDb->prepare('
        DELETE FROM tbl_sessions WHERE id = :sessionid AND accesstoken = :accesstoken
        ');
        $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accessToken, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
          $response = new Response();
          $response->setHttpStatusCode(400);
          $response->setSuccess(false);
          $response->addMessage('Failed to logout using access token provided');
          $response->send();
          exit;
        }

        $returnData = [];
        $returnData['session_id'] = intval($sessionid);

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->addMessage('Logged out');
        $response->setData($returnData);
        $response->send();
        exit;

      } catch (PDOException $ex) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('There was an issue logging out - please try again');
        $response->send();
        exit;
      }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

      if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Content type header is not set to json');
        $response->send();
        exit;
      }

      $rawPatchData = file_get_contents('php://input');

      if (!$jsonData = json_decode($rawPatchData)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Request body is not valid json');
        $response->send();
        exit;
      }

      if (
        !isset($jsonData->refresh_token) ||
        strlen($jsonData->refresh_token) < 1
      ) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (!isset($jsonData->refresh_token) ? $response->addMessage('Refresh token not supplied') : false);
        (strlen($jsonData->refresh_token) < 1 ? $response->addMessage('Refresh token cannot be blank') : false);
        $response->send();
        exit;

      }

      try {

        $refreshToken = $jsonData->refresh_token;

        $query = $writeDb->prepare('
        SELECT 
            tbl_sessions.id as sessionid, 
            tbl_sessions.userid as userid, 
            accesstoken,
            refreshtoken,
            useractive,
            loginattempts,
            accesstokenexpiry,
            refreshtokenexpiry
        FROM 
            tbl_sessions, 
            tbl_users
        WHERE 
            tbl_users.id = tbl_sessions.userid
            AND
            tbl_sessions.id = :sessionid
            AND 
            tbl_sessions.accesstoken = :accesstoken
            AND
            tbl_sessions.refreshtoken = :refreshtoken
        ');

        $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accessToken, PDO::PARAM_STR);
        $query->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
          $response = new Response();
          $response->setHttpStatusCode(401);
          $response->setSuccess(false);
          $response->addMessage('Access token or refresh token is incorrect');
          $response->send();
          exit;
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_sessionid = $row['sessionid'];
        $returned_userid = $row['userid'];
        $returned_accesstoken = $row['accesstoken'];
        $returned_refreshtoken = $row['refreshtoken'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];
        $returned_accesstokenexpiry = $row['accesstokenexpiry'];
        $returned_refreshtokenexpiry = $row['refreshtokenexpiry'];

        if($returned_useractive !== 'Y'){
          $response = new Response();
          $response->setHttpStatusCode(401);
          $response->setSuccess(false);
          $response->addMessage('User account is not active');
          $response->send();
          exit;
        }

        if($returned_loginattempts >= 3){
          $response = new Response();
          $response->setHttpStatusCode(401);
          $response->setSuccess(false);
          $response->addMessage('User account is currently locked out');
          $response->send();
          exit;
        }

        if(strtotime($returned_refreshtokenexpiry) < time()){
          $response = new Response();
          $response->setHttpStatusCode(401);
          $response->setSuccess(false);
          $response->addMessage('Refresh token is expired - please login again');
          $response->send();
          exit;
        }

        $accessToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
        $refreshToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)). time());

        $access_token_expiry_time = 1200;
        $refresh_token_expiry_time = 1209600;

        $query = $writeDb->prepare('
          UPDATE
            tbl_sessions
          SET
            accesstoken = :accesstoken,
            accesstokenexpiry = date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND),
            refreshtoken = :refreshtoken,
            refreshtokenexpiry = date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND)
          WHERE
            id = :sessionid
            AND
            userid = :userid
            AND 
            accesstoken = :returnedaccesstoken
            AND 
            refreshtoken = :returnedrefreshtoken
        ');


        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accessToken, PDO::PARAM_STR);
        $query->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
        $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_time, PDO::PARAM_INT);
        $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_time, PDO::PARAM_INT);
        $query->bindParam(':returnedaccesstoken', $returned_accesstoken, PDO::PARAM_STR);
        $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);

        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
          $response = new Response();
          $response->setHttpStatusCode(401);
          $response->setSuccess(false);
          $response->addMessage('Access token could not be refreshed - please login again');
          $response->send();
          exit;
        }

        $returnData = [];
        $returnData['session_id'] = $returned_sessionid;
        $returnData['access_token'] = $accessToken;
        $returnData['access_token_expiry'] = $access_token_expiry_time;
        $returnData['refresh_token'] = $refreshToken;
        $returnData['refresh_token_expiry'] = $refresh_token_expiry_time;

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->addMessage('Token refreshed');
        $response->setData($returnData);
        $response->send();
        exit;

      } catch (PDOException $ex) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('There was an issue refreshing access token - please login again');
        $response->send();
        exit;
      }

    } else {
      $response = new Response();
      $response->setHttpStatusCode(405);
      $response->setSuccess(false);
      $response->addMessage('Request method not allowed');
      $response->send();
      exit;
    }


  } elseif (empty($_GET)) {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $response = new Response();
      $response->setHttpStatusCode(405);
      $response->setSuccess(false);
      $response->addMessage("Request method not allowed");
      $response->send();
      exit;
    }

    sleep(1);

    if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage("Content type is not set to json");
      $response->send();
      exit;
    }

    $rawPostData = file_get_contents('php://input');

    if (!$jsonData = json_decode($rawPostData)) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage("Request body is not valid json");
      $response->send();
      exit;
    }

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      (!isset($jsonData->username) ? $response->addMessage("username is not supplied") : false);
      (!isset($jsonData->password) ? $response->addMessage("password is not supplied") : false);
      $response->send();
      exit;
    }

    if (
      strlen($jsonData->username) < 1 ||
      strlen($jsonData->username) > 255 ||
      strlen($jsonData->password) < 1 ||
      strlen($jsonData->password) > 255
    ) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      strlen($jsonData->username) < 1 ? $response->addMessage("user name cannot be blank") : false;
      strlen($jsonData->username) > 255 ? $response->addMessage("user name cannot be more than 255 characters") : false;
      strlen($jsonData->password) < 1 ? $response->addMessage("password cannot be blank") : false;
      strlen($jsonData->password) > 255 ? $response->addMessage("password cannot be more than 255 characters") : false;
      $response->send();
      exit;
    }

    $username = trim($jsonData->username);
    $password = $jsonData->password;

    try {
      $query = $writeDb->prepare(
        'SELECT id, fullname, username, password, useractive, loginattempts 
                FROM tbl_users WHERE username = :username'
      );
      $query->bindParam(':username', $username, PDO::PARAM_STR);
      $query->execute();

      $rowCount = $query->rowCount();

      if ($rowCount == 0) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Username or password is incorrect");
        $response->send();
        exit;
      }

      $row = $query->fetch(PDO::FETCH_ASSOC);

      $returned_id = $row['id'];
      $returned_fullname = $row['fullname'];
      $returned_username = $row['username'];
      $returned_password = $row['password'];
      $returned_useractive = $row['useractive'];
      $returned_loginattempts = $row['loginattempts'];

      if ($returned_useractive !== 'Y') {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account not active");
        $response->send();
        exit;
      }

      if ($returned_loginattempts >= 3) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is currently locked out");
        $response->send();
        exit;
      }

      if (!password_verify($password, $returned_password)) {
        $query = $writeDb->prepare('UPDATE tbl_users SET loginattempts = loginattempts+1 WHERE id = :id');
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User name or password is incorrect");
        $response->send();
        exit;
      }

      $accessToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());
      $refreshToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());

      /**
       * 12 seconds
       */
      $access_token_expiry = 1200;
      /**
       * 14 days
       */
      $refresh_token_expiry = 1209600;
    } catch (PDOException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("There was a issue logging in");
      $response->send();
      exit;
    }

    try {
      $writeDb->beginTransaction();

      $query = $writeDb->prepare('
      UPDATE tbl_users SET loginattempts = 0 WHERE id = :id
      ');

      $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
      $query->execute();

      $query = $writeDb->prepare(
        'INSERT INTO tbl_sessions 
                (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) 
                values (
                        :userid, 
                        :accesstoken, 
                        date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), 
                        :refreshtoken, 
                        date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND))'
      );

      $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
      $query->bindParam(':accesstoken', $accessToken, PDO::PARAM_STR);
      $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry, PDO::PARAM_INT);
      $query->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
      $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry, PDO::PARAM_INT);

      $query->execute();


      $lastSessionId = $writeDb->lastInsertId();
      $writeDb->commit();

      $returnData = [];
      $returnData['session_id'] = intval($lastSessionId);
      $returnData['access_token'] = $accessToken;
      $returnData['access_token_expires_in'] = $access_token_expiry;
      $returnData['refresh_token'] = $refreshToken;
      $returnData['refresh_token_expires_in'] = $refresh_token_expiry;

      $response = new Response();
      $response->setHttpStatusCode(201);
      $response->setSuccess(true);
      $response->setData($returnData);
      $response->send();
      exit;

    } catch (PDOException $ex) {
      $writeDb->rollBack();
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("There was a issue logging in - please try again");
      $response->send();
      exit;
    }


  } else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found");
    $response->send();
    exit;
  }
