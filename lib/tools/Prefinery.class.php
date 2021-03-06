<?php

class Prefinery
{
  const STATE_APPLIED   = 'applied';
  const STATE_INVITED   = 'invited';
  const STATE_IMPORTED  = 'imported';
  const STATE_REJECTED  = 'rejected';
  const STATE_ACTIVE    = 'active';
  const STATE_SUSPENDED = 'suspended';

  const DOMAIN = 'https://lbry.prefinery.com';
  const PREFIX = '/api/v2/betas/8679';

  protected static $curlOptions = [
    'headers'   => [
      'Accept: application/json',
      'Content-type: application/json'
    ],
    'json_post' => true
  ];


  public static function findUser($emailOrId)
  {
    $user = is_numeric($emailOrId) ? Prefinery::findTesterById($emailOrId) : Prefinery::findTesterByEmail($emailOrId);
    if ($user)
    {
      unset($user['invitation_code']); // so we dont leak it
    }
    return $user;
  }

  protected static function findTesterById($id)
  {
    return static::get('/testers/' . (int)$id);
  }

  protected static function findTesterByEmail($email)
  {
    $data = static::get('/testers', ['email' => $email]);

    if ($data && is_array($data) && count($data))
    {
      foreach ($data as $userData) //can partial match on email, very unlikely though
      {
        if (strtolower($userData['email']) == strtolower($email))
        {
          return $userData;
        }
      }
      return $data[0];
    }

    return null;
  }

  public static function findOrCreateUser($email, $inviteCode = null, $referrerId = null)
  {
    $user = static::findUser($email);
    if (!$user)
    {
      // dont record ip for lbry.io addresses, for testing
      $ip = isset($_SERVER['REMOTE_ADDR']) && !preg_match('/@lbry\.io$/', $email) ? $_SERVER['REMOTE_ADDR'] : null;
      $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
      $user = Prefinery::createTester(array_filter([
        'email'           => $email,
        'status'          => $inviteCode ? static::STATE_ACTIVE : static::STATE_APPLIED, # yes, has to be ACTIVE to validate invite code
        'invitation_code' => $inviteCode,
        'referrer_id'     => $referrerId,
        'profile'         => ['ip' => $ip, 'user_agent' => $ua]
      ]));
    }

    unset($user['invitation_code']); // so we dont leak it
    return $user;
  }

  protected static function createTester(array $testerData)
  {
    return static::post('/testers', ['tester' => array_filter($testerData)], false);
  }

  protected static function get($endpoint, array $data = [])
  {
    $apiKey = Config::get('prefinery_key');
    return static::decodePrefineryResponse(
      Curl::get(static::DOMAIN . static::PREFIX . $endpoint . '.json?api_key=' . $apiKey, $data, static::$curlOptions)
    );
  }

  protected static function post($endpoint, array $data = [], $allowEmptyResponse = true)
  {
    $apiKey = Config::get('prefinery_key');
    return static::decodePrefineryResponse(
      Curl::post(static::DOMAIN . static::PREFIX . $endpoint . '.json?api_key=' . $apiKey, $data, static::$curlOptions),
      $allowEmptyResponse
    );
  }

  protected static function decodePrefineryResponse($rawBody, $allowEmptyResponse = true)
  {
    if (!$rawBody)
    {
      throw new PrefineryException('Empty cURL response.');
    }

    $data = json_decode($rawBody, true);

    if (!$allowEmptyResponse && !$data && $data !== [])
    {
      throw new PrefineryException('Received empty or improperly encoded response.');
    }

    if (isset($data['error']))
    {
      throw new PrefineryException($data['error']);
    }

    if (isset($data['errors']))
    {
      throw new PrefineryException(implode("\n", array_map(function ($error)
      {
        return $error['message'];
      }, (array)$data['errors'])));
    }

    return $data;
  }
}

class PrefineryException extends Exception
{
}
