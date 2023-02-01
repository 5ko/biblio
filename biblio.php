<?php if (!defined('PmWiki')) exit();
/**
  Reference/bibliography module for PmWiki/Allegro
  Written by (c) Petko Yotov 2023  www.pmwiki.org/petko

  This text is written for PmWiki; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published
  by the Free Software Foundation; either version 3 of the License, or
  (at your option) any later version. See pmwiki.php for full details
  and lack of warranty.
*/
$RecipeInfo['Biblio']['Version'] = '20230201';

$HandleActions['ref'] = 'HandleReference';
$EditFunctions[] = 'AllegroSaveBiblio';

function HandleBiblioNew($pagename, $auth='edit') {
  global $PageStartFmt, $PageEndFmt;
  
  
  $fmt = array(&$PageStartFmt, '<div class="bnew">', '(:pmform biblio:)', '</div>', &$PageEndFmt);
//   xmp([$pagename, $auth, $fmt]);

  PrintFmt($pagename, $fmt);

}



function AllegroSaveBiblio($pagename, $page, $new) {
  global $IsPagePosted, $action, $Allegro;
  if(!$IsPagePosted) return;
  
  list($g, $n) = explode('.', $pagename);
  if($g != 'Bibliography') return;
  
  $vars = explode(' ', 'RType Authors Title IssueTitle DatePub YearPub Language URL DateVisited Publisher Location ISBN ISSN DOI OCLC LCCN Bibcode');
  
  $out = [];
  
  foreach($vars as $key) {
    $v = trim(PageTextVar($pagename, $key));
    if(!$v) continue;
    $out[$key] = $v;
  }
  
  ksort($out);

  $bibfname = $Allegro['DataDir']. '/biblio.json';
  $allstrings = aform_jfile($bibfname);
  
  if(@$allstrings[$n] == $out) return;
  $allstrings[$n] = $out;

  aform_jfile($bibfname, $allstrings);
  
  
}


function HandleReference($pagename, $auth='edit') {
  
  $url = @$_REQUEST['url'];
  $page = RetrieveAuthPage($pagename, 'edit', false, READPAGE_CURRENT);
  if(!$page || ! $url) {
    echo '{}';
    die;
  }
  
  mb_detect_order('UTF-8, UTF-7, ISO-8859-1');
  
  @session_start();
  if(isset($_SESSION['remoterefs'][$url])) {
    $html = $_SESSION['remoterefs'][$url];
    
  }
  else {
    $html = get_remote($url);
    $_SESSION['remoterefs'][$url] = $html;
    session_write_close();
  }
  
  $out = $json = [];
  
  if(preg_match('!<title>(.*?)</title>!is', $html, $t)) {
    $out['Title'] = fix_remote_HTML($t[1]);
  }
  
  preg_match_all('!<meta\\s(.*?)/?>!i', $html, $m);
  
  
  foreach($m[1] as $meta) {
    $attrs = ParseArgs($meta);
    $attrsLC = [];
    foreach($attrs as $k=>$v) {
      $attrsLC[ strtolower($k) ] = $v;
    }
    
    if(@$attrsLC['name'] && @$attrsLC['content']) {
      
      $out[strtolower($attrsLC['name'])] = fix_remote_HTML($attrsLC['content']);
    }
  }
//   xmp($out, 1);
  
  $MetaRX = [
    '!^(citation_|dc\\.)?(authors?|creators?)$!' => 'Authors',
    '!^(citation_|dc\\.)?(title)$!i' => 'Title',
    '!^(citation_|dc\\.)?(publisher)$!' => 'Publisher',
    '!^(citation_publication_|citation_|dc\\.)?(date(\\.issued|modified|published)?)$!' => 'DatePub',
    '!^(dc\\.|content-|citation_)?language$!' => 'Language',
    '!^(citation_)?doi$!' => 'DOI',
    '!^(citation_)?issn$!' => 'ISSN',
    '!^(citation_)?isbn$!' => 'ISBN',
    '!^(citation_)?journal_title$!' => 'IssueTitle',
    
  ];
  

  foreach($out as $k=>$v) {
    foreach($MetaRX as $rx=>$prop) {
      if(preg_match($rx, $k)) $json[$prop] = $v;
    }
  
  }
  
  if(preg_match('!<script\\s+type=([\'"])application/ld\\+json\\1>(.*?)</script>!si', $html, $ld)) {
    $ld = $el = AllegroJD(html_entity_decode($ld[2]));
//       xmp($ld, 1);
    
    if(isset($ld['dataFeedElement'][0])) {
      $el = $ld['dataFeedElement'][0];
    }
    
    if(@$el['author']['name']) $json['Authors'] = $el['author']['name'];
    if(@$el['name']) $json['Title'] = $el['name'];
    if(@$el['datePublished']) $json['DatePub'] = $el['datePublished'];
    if(@$el['publisher']['name']) $json['Publisher'] = $el['publisher']['name'];
    if(isset($el['workExample'][0])) {
      $we = $el['workExample'][0];
      if(@$we['@type']) $json['RType'] = strtolower($we['@type']);
      if(@$we['inLanguage']) $json['Language'] = substr(strtolower($we['inLanguage']), 0, 2);
      if(@$we['datePublished']) $json['DatePub'] = $we['datePublished'];
      if(@$we['isbn']) $json['ISBN'] = $we['isbn'];
      if(@$we['identifier']['propertyID'] == 'OCLC_NUMBER') {
        $json['OCLC'] = @$we['identifier']['value'];
      }
    }
    
    $out['.ld+json'] = $ld;
  }
  
  if($html) {
    $json['DateVisited'] = PSFT('%F');
  }
  
  if(@$json['Language']) {
    $split = preg_split('/[^\\w]+/', $json['Language'], -1, PREG_SPLIT_NO_EMPTY);
    if($split[0]) $json['Language'] = strtolower($split[0]);
  }
  if(@$json['DatePub']) {
    $json['DatePub'] = preg_replace('!(\\d\\d\\d\\d)/(\\d\\d)/(\\d\\d)$!', '$1-$2-$3', $json['DatePub']);
    $json['DatePub'] = preg_replace('!(\\d\\d\\d\\d-\\d\\d-\\d\\d).*$!', '$1', $json['DatePub']);
    if(preg_match('!^(?:(\\d\\d?)[-/])?(\\d\\d\\d\\d)$!', $json['DatePub'], $y)) {
      unset($json['DatePub']);
      $json['YearPub'] = $y[2];
    }
    
  }
  
  $j = json_encode(['found'=>$json, 'all'=>$out]);
  
  header('Content-type: application/json');
  die($j);
  
//   xmp($out);
//   xmp($json);
  
  
}

function get_remote($url) {
  global $WorkDir;
  $cookiefile = "$WorkDir/.curl-cookies";
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_AUTOREFERER, true);
//   curl_setopt($ch, CURLOPT_COOKIESESSION, true);
  
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:106.0) Gecko/20100101 Firefox/106.0');
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLINFO_HEADER_OUT, true);
  curl_setopt($ch, CURLOPT_ENCODING, '');
  
  curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiefile);
  curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiefile);
  
  $html = curl_exec($ch);
  
  curl_close($ch); 
  $headercharset = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  
  $myheaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
  
  
  $enc = false;
  if(preg_match('!<meta\\s[^>]*charset=[\'"]?([-\\w]+)!i', $html, $m)) {
    $enc = $m[1];
  }
  elseif(preg_match('!charset=[\'"]?([-\\w]+)!i', $headercharset, $m)) {
    $enc = $m[1];    
  }
  else {
    $enc = mb_detect_encoding($html);
  }
  
  if($enc && ! preg_match('/^utf-?8$/i', $enc)) {
    $html = mb_convert_encoding($html, 'UTF-8', $enc);
  }
   
  return "$myheaders\n\n$html";
  
}

function fix_remote_HTML($str) {
  $str = trim(preg_replace('/\\s+/', ' ', $str));
  $str = html_entity_decode($str, ENT_QUOTES | ENT_SUBSTITUTE , 'UTF-8');
  return $str;
}
