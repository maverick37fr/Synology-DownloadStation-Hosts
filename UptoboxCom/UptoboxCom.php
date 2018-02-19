<?php

/*Auteur : warkx
  Partie premium developpé par : Einsteinium
  Aidé par : Polo.Q, Samzor
  Version : 1.5.2
  Développé le : 19/02/2018
  Description : Support du compte gratuit et premium*/
  
  
class SynoFileHosting
{
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;
    private $CookieValue;
  
    private $COOKIE_FILE = '/tmp/uptobox.cookie';
    private $LOGIN_URL = 'https://login.uptobox.com/logarithme';
    private $ACCOUNT_TYPE_URL = 'https://uptobox.com/?op=my_account';
  
    private $FILE_NAME_REGEX = '/<title>(.+?)<\/title>/si';
    private $FILE_OFFLINE_REGEX = '/The file was deleted|Page not found/i';
    private $DOWNLOAD_WAIT_REGEX = '/can wait (.+) to launch a new download/i';
    private $FILE_URL_REGEX = '`(https?:\/\/\w+\.uptobox\.com\/dl\/.+?)(?:"|\n|$)`si';
    private $ACCOUNT_TYPE_REGEX = '/Premium\s*member/i';
    private $ERROR_404_URL_REGEX = '/uptobox.com\/404.html/i';
  
    private $STRING_COUNT = 'count';
    private $STRING_FNAME = 'fname';
    private $QUERYAGAIN = 1;
    private $WAITING_TIME_DEFAULT = 1800;
    
    public function __construct($Url, $Username, $Password, $HostInfo) 
    {
		$this->Url = $Url;
		$this->Username = $Username;
		$this->Password = $Password;
		$this->HostInfo = $HostInfo;
	}
  
    //se connecte et renvoie le type du compte
    public function Verify($ClearCookie)
    {
        $ret = LOGIN_FAIL;
        $this->CookieValue = false;
  
        //si le nom d'utilisateur et le mot de passe sont entré on se connecte
        //renvoie le cookie si la connexion est initialisé
        if(!empty($this->Username) && !empty($this->Password)) 
        {
            $this->CookieValue = $this->Login($this->Username, $this->Password);
        }
        //Verifie le type de compte
        if($this->CookieValue != false) 
        {
            $ret = $this->AccountType($this->Username, $this->Password);
        }
        if ($ClearCookie && file_exists($this->COOKIE_FILE)) 
        {
            unlink($this->COOKIE_FILE);
        }
        return $ret;
    }
    
    //Lance le telechargement en fonction du type de compte
    public function GetDownloadInfo()
    {
        $ret = false;
        $VerifyRet = $this->Verify(false);
    
        if(USER_IS_PREMIUM == $VerifyRet)
        {
            $ret = $this->DownloadPremium();
      
        }else if(USER_IS_FREE == $VerifyRet)
        {
            $ret = $this->DownloadWaiting(true);
        }else
        {
            $ret = $this->DownloadWaiting(false);
        }
    
        if($ret != false)
        {
            $ret[INFO_NAME] = trim($this->HostInfo[INFO_NAME]);
        }
    
        return $ret;
    }
  
    //Telechargement en mode premium
    private function DownloadPremium()
    {
        $ret = false;
        $DownloadInfo = array();
        $ret = $this->UrlFilePremium();

        if($ret == false)
        {
            $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
        }else
        {
          $page = $ret;
          
          preg_match($this->FILE_NAME_REGEX, $page, $filenamematch);
          if(!empty($filenamematch[1]))
          {
            $DownloadInfo[DOWNLOAD_FILENAME] = $filenamematch[1];
          }
          
          preg_match($this->FILE_URL_REGEX,$page,$urlmatch);
          if(!empty($urlmatch[1]))
          {
            $DownloadInfo[DOWNLOAD_URL] = $urlmatch[1];
          }else
          {
            $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
          }
          
          $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
          $DownloadInfo[DOWNLOAD_COOKIE] = $this->COOKIE_FILE;
        }
        return $DownloadInfo;
    }
    
    //telechargement en mode gratuit ou sans compte
    private function DownloadWaiting($LoadCookie)
    {
        $DowloadInfo = false;
        $page = $this->DownloadParsePage($LoadCookie);

        if($page != false)
        {
            //Termine la fonction si le fichier est offline
            preg_match($this->FILE_OFFLINE_REGEX,$page,$errormatch);
            if(isset($errormatch[0]))
            {
                $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
            }else
            {
                //verifie s'il faut attendre et si c'est le cas, renvoie le temps d'attente
                $result = $this->VerifyWaitDownload($page);
                if($result != false)
                {
                    $DownloadInfo[DOWNLOAD_COUNT] = $result[$this->STRING_COUNT];
                    $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
                }else
                {
                    //genere la requete pour cliquer sur "Generer le lien" et recupere le nom du fichier
                    preg_match($this->FILE_NAME_REGEX, $page, $filenamematch);
                    if(!empty($filenamematch[1]))
                    {
                        $DownloadInfo[DOWNLOAD_FILENAME] = $filenamematch[1];
                    }
                    
                    preg_match($this->FILE_URL_REGEX,$page,$urlmatch);
                    if(!empty($urlmatch[1]))
                    {
                        $DownloadInfo[DOWNLOAD_URL] = $urlmatch[1];
                    }else
                    {
                        $DownloadInfo[DOWNLOAD_COUNT] = $this->WAITING_TIME_DEFAULT;
                        $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = $this->QUERYAGAIN;
                    }
                }
                $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = false;
            }
            if($LoadCookie == true)
            {
                $DownloadInfo[DOWNLOAD_COOKIE] = $this->COOKIE_FILE;
            }
        }
        return $DownloadInfo;
    }
    
    //Renvoie le temps d'attente indiqué sur la page, ou false s'il n'y en a pas
    private function VerifyWaitDownload($page)
    {
        $ret = false;
        
        preg_match($this->DOWNLOAD_WAIT_REGEX, $page, $waitingmatch);
        if(!empty($waitingmatch[0]))
        {
            if(!empty($waitingmatch[1]))
            {
                $waitingtime = 0;
                preg_match('`(\d+) hour`si', $waitingmatch[1], $waitinghourmatch);
                if(!empty($waitinghourmatch[1]))
                {
                    $waitingtime = ($waitinghourmatch[1] * 3600);
                }
                preg_match('`(\d+) minute`si', $waitingmatch[1], $waitingminmatch);
                if(!empty($waitingminmatch[1]))
                {
                    $waitingtime = $waitingtime + ($waitingminmatch[1] * 60) + 70;
                }
            }else
            {
                $waitingtime = 70;
            }
            $ret[$this->STRING_COUNT] = $waitingtime;
        }
        return $ret;
    }
  
    //authentifie l'utilisateur sur le site
    private function Login()
    {
        $ret = LOGIN_FAIL;
		$PostData = array('login'=>$this->Username,
                        'password'=>$this->Password,
                        'op'=>'login');
            
		$queryUrl = $this->LOGIN_URL;
		$PostData = http_build_query($PostData);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $PostData);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_COOKIEJAR, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $queryUrl);
		$LoginInfo = curl_exec($curl);
		curl_close($curl);
		
		if ($LoginInfo != false && file_exists($this->COOKIE_FILE)) 
        {
			$cookieData = file_get_contents ($this->COOKIE_FILE);
			if(strpos($cookieData,'xfss') == true) 
            {
				$ret = true;
			}else 
            {
                $ret = false;
            }
		}
		return $ret;
    }
  
    //renvoie premium si le compte est premium sinon concidere qu'il est gratuit
    private function AccountType()
    {
        $ret = false;
        $curl = curl_init();
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $this->ACCOUNT_TYPE_URL);
		$page = curl_exec($curl);
		curl_close($curl);
    
        preg_match($this->ACCOUNT_TYPE_REGEX,$page,$accouttypematch);
        if(isset($accouttypematch[0]))
        {
            $ret = USER_IS_PREMIUM;
        }else
        {
            $ret = USER_IS_FREE;
        }
        return $ret;
    }
    
    //affiche la page en mode gratuit
    private function DownloadParsePage($LoadCookie)
    {
        $ret = false;
        $curl = curl_init(); 
    
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        if($LoadCookie == true)
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
        }
        curl_setopt($curl, CURLOPT_HEADER, TRUE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE); 
        curl_setopt($curl, CURLOPT_URL, $this->Url); 
    
        $ret = curl_exec($curl); 
        $info = curl_getinfo($curl);
        curl_close($curl);
    
        $this->Url = $info['url'];
        return $ret; 
    }
    
    //renvoie la vrai url du fichier en mode premium. Ou false si elle n'est pa affiché
    private function UrlFilePremium()
    {
        $ret = false;
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_URL, $this->Url);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($curl, CURLOPT_HEADER, true);

		$header = curl_exec($curl);
		$info = curl_getinfo($curl);
        curl_close($curl);
    
		$error_code = $info['http_code'];
		
		if ($error_code == 301 || $error_code == 302) 
        { 
			$ret = $info['redirect_url'];
		}
        preg_match($this->ERROR_404_URL_REGEX, $ret, $finderror);
        if(isset($finderror[0]))
        {
            $ret = false;
        }else
        {
            $ret = $header;
        }
		return $ret;
    }
}
?>