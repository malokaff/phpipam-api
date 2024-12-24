<?php
#manage ip range in phpipam using API
#by Benjamin Maze December 2024
$curl = curl_init();
include('config.php');
function ipamRequest($url,$method,$body) {
	global $token;
	global $curl;
	curl_setopt_array($curl, array(
	  CURLOPT_URL => $url,
	  CURLOPT_SSL_VERIFYHOST => false,
	  CURLOPT_SSL_VERIFYPEER => false,
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_ENCODING => '',
	  CURLOPT_MAXREDIRS => 10,
	  CURLOPT_TIMEOUT => 0,
	  CURLOPT_FOLLOWLOCATION => true,
	  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	  CURLOPT_CUSTOMREQUEST => $method ,
	  CURLOPT_POSTFIELDS => $body,
	  CURLOPT_HTTPHEADER => array('token: '.$token,'Content-Type: application/json')
	  //CURLOPT_HTTPHEADER => array('token: AMczqWTl8hKSfzhJpGcVdmDY1PjTRhom')
	));
	$response = curl_exec($curl);

	if (curl_errno($curl)) {
		echo curl_error($curl);
		die();
	}

	$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	if ($http_code == intval(200)) {
		return $response;
	} else {
		return "Ressource introuvable : " . $http_code . $response;
	}

	curl_close($curl);
}


echo "<b>update ip range phpipam</b><br><br>";

//get the list of subnet
?>
<form action="index.php" method="get">
  <label for="subnets">choose your subnet in the list</label>
  <select name='subnet' id='subnet' onchange="this.form.submit()">
	<option value=""></option>
<?php

$response = ipamRequest($APIurl.'/subnets','GET','');
//echo $response;
$decodedJson = json_decode($response, true);
//var_dump($decodedJson);
foreach ($decodedJson['data'] as $key => $value) {
	echo '<option value="'.$value['id'].'"';
	if(isset($_GET['subnet']) && $_GET['subnet'] == $value['id'])
		echo " selected";
	echo '>'.$value['description'].'</option>';
	//echo '<option data-value="'.$value['id'].'" value="'.$value['subnet'].'">';
}
?>
  </select>
</form>

<?php
//select the range where we want to do the change
if(isset($_GET['subnet']) || isset($_POST['subnet'])) {
	$response = ipamRequest($APIurl.'/subnets/'.$_GET['subnet'],'GET','');
	//echo $response;
	$decodedJson = json_decode($response, true);
	$subnet= $decodedJson['data']["subnet"];
	$parts = explode('.', $subnet);
	// Remove the last part and join the rest
	$subnetFirstPart = implode('.', array_slice($parts, 0, 3));
	echo '<form action="index.php?subnet='.$_GET['subnet'].'" method="POST">';
	echo "<table>";
		echo '<tr>';
			echo '<td><b>'.$subnetFirstPart.'.</b></td>';
			echo '<td>from<br><input type=text name="start"';
			if(isset($_POST['start']))
				echo ' value="'.$_POST['start'].'"';
			echo '></td>';
			echo '<td>to<br><input type=text name="stop"';
			if(isset($_POST['stop']))
				echo ' value="'.$_POST['stop'].'"';
			echo '></td>';
			echo '<input type=hidden name="subnet" value="';
			if(isset($_POST['subnet'])) echo $_POST['subnet'];
			echo '">';
			echo '<input type=hidden name="action" value="editRange"></td>';
		echo '</tr><tr>';
			echo "<td>tag: <br><select name='tags' id='tags'><option value=''></option>";
				echo "<option value='1'";
				if(isset($_POST['tags']) && $_POST['tags']==1)
					echo " selected";
				echo ">Offline</option>";
				echo "<option value='2'";
				if(isset($_POST['tags']) && $_POST['tags']==2)
					echo " selected";
				echo ">Used</option>";
				echo "<option value='3'";
				if(isset($_POST['tags']) && $_POST['tags']==3)
					echo " selected";
				echo ">Reserved</option>";
				echo "<option value='4'";
				if(isset($_POST['tags']) && $_POST['tags']==4)
					echo " selected";
				echo ">DHCP</option></select></td>";
			echo '<td>hostname<br><input type=text name="hostname" value="';
			if(isset($_POST['hostname'])) echo $_POST['hostname'];
			echo '"></td>';
			echo '<td>description<br><input type=text name="description" value="';
			if(isset($_POST['description'])) echo $_POST['description'];
			echo '"></td>';
		echo '</tr><tr>';
			echo '<td>delete <input type="checkbox" name="delete"';
				if(isset($_POST['delete']) && $_POST['delete']=='on')
					echo " checked";
			echo '></td>';
			echo '<td colspan=2><input type="submit"></td>';
	echo "</tr></table></form>";
	
	//var_dump($decodedJson);
}

//run a loop to get info on each ip
if(isset($_POST['action']) && $_POST['action']=="editRange")  {
	$response = ipamRequest($APIurl.'/subnets/'.$_GET['subnet'],'GET','');
	//echo $response;
	$decodedJson = json_decode($response, true);
	$subnet= $decodedJson['data']["subnet"];
	$parts = explode('.', $subnet);
	// Remove the last part and join the rest
	$subnetFirstPart = implode('.', array_slice($parts, 0, 3));
	for($i = $_POST['start']; $i <= $_POST['stop']; $i++) {
		$ip = $subnetFirstPart.'.'.$i;
		//check if ip exist and get id
		$response = ipamRequest($APIurl.'/addresses/search/'.$ip,'GET','');
		//echo $response;
		$decodedJson = json_decode($response, true);
		//case1: ip already exist, need to PATCH
		$tabUpdate = [];
		if(isset($_POST['confDel'])) 
			$confDel=$_POST['confDel'];
		else
			$confDel="no";
		if(isset($decodedJson['code']) && $decodedJson['code']=='200' && $confDel != 'yes') {
			$addressDetail= $decodedJson['data'][0];
			$id= $addressDetail['id'];
			$ip = $addressDetail['ip'];
			echo '<br>'.$ip.' with id '.$id.' - ';
			if($_POST['tags']!=''){
				$itemUpdate["tag"]=$_POST['tags'];
				$tabUpdate[] = $itemUpdate;
			}
			if($_POST['hostname']!='') {
				$itemUpdate["hostname"]=$_POST['hostname'];
				$tabUpdate[] = $itemUpdate;
			}
			if($_POST['description']!='') {
				$itemUpdate["description"]=$_POST['description'];
				$tabUpdate[] = $itemUpdate;
				}
			//print_r($tabUpdate);
			$sizeTab=count($tabUpdate)-1;
			$body = json_encode($tabUpdate[$sizeTab], JSON_PRETTY_PRINT);
			$url = $APIurl.'/addresses/'.$id;
			$response = ipamRequest($url,'PATCH',$body);
			echo $response;
			
		}
		//case2: new ip need to POST
		elseif($confDel != 'yes') {
			echo '<br>'.$ip.' not found - ';
			if($_POST['tags'] != '')
				$tag=',"tag": '.$_POST['tags'];
			else
				$tag='';
			$body= '{"ip": "'.$ip.'","subnetId": '.$_GET['subnet'].$tag.',"hostname": "'.$_POST['hostname'].'","description": "'.$_POST['description'].'"}';
			echo $body;
			$response = ipamRequest($APIurl.'/addresses/','POST',$body);
			echo $response;
		}
		//case2: ip delete DELETE
		elseif($confDel == 'yes'){
			$addressDetail= $decodedJson['data'][0];
			$id= $addressDetail['id'];
			echo "<br>delete ip addresses ".$ip.' - ';
			$url = $APIurl.'/addresses/'.$id;
			//echo $url;
			$response = ipamRequest($url,'DELETE','');
			echo $response;
		}
		//echo $response;
		}
	//delete with confirmation
	if(isset($_POST["delete"]) && $_POST["delete"]=='on') {
		echo '<form name="confirmDelete" action="index.php?subnet='.$_GET['subnet'].'" method=POST>';
		echo '<input type=hidden name="confDel" value="yes">';
		echo '<input type=hidden name="action" value="editRange">';
		echo '<input type=hidden name="start" value="'.$_POST["start"].'">';
		echo '<input type=hidden name="stop" value="'.$_POST["stop"].'">';
		echo '<input type=hidden name="subnet" value="'.$_GET["subnet"].'">';
		echo '<input type=submit value="confirm delete ?"></form>';
	}	
}

?>



