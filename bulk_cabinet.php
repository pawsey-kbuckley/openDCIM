<?php
	require_once('db.inc.php');
	require_once('facilities.inc.php');

  if(!$person->BulkOperations){
    header('Location: '.redirect());
    exit;
  }

//	Uncomment these if you need/want to set a title in the header
//	$header=__("");
	$subheader=__("Bulk Cabinet Importer");

  $content = "";

  if ( isset( $_FILES['inputfile'] )) {
    //
    //  File name has been specified, so we're uploading a new file.  Need to simply make sure
    //  that it's at least a valid file that PHPExcel can open and that we can move it to
    //  the /tmp directory.  We'll set the filename as a session variable so that we can keep track
    //  of it more simply as we move from stage to stage.
    //
    $target_dir = '/tmp/';
    $targetFile = $target_dir . basename($_FILES['inputfile']['name']);

    try {
      $inFileType = PHPExcel_IOFactory::identify($_FILES['inputfile']['tmp_name']);
      $objReader = PHPExcel_IOFactory::createReader($inFileType);
      $objXL = $objReader->load($_FILES['inputfile']['tmp_name']);
    } catch (Exception $e) {
      die("Error opening file: ".$e->getMessage());
    }

    move_uploaded_file( $_FILES['inputfile']['tmp_name'], $targetFile );

    $_SESSION['inputfile'] = $targetFile;

    echo "<meta http-equiv='refresh' content='0; url=" . $_SERVER['SCRIPT_NAME'] . "?stage=headers'>";
    exit;
  } elseif ( isset( $_REQUEST['stage'] ) && $_REQUEST['stage'] == 'headers' ) {
    //
    //  File has been moved, so now we're ready to map out the columns to fields for processing.
    //  If you don't want to have to map every time, you can simply make your spreadsheet columns
    //  appear in the same order as they show up on this page.  That way you can just click right
    //  on to the next stage, which is validation.
    //

    // Make sure that we can still access the file
    $targetFile = $_SESSION['inputfile'];
    try {
      $inFileType = PHPExcel_IOFactory::identify($targetFile);
      $objReader = PHPExcel_IOFactory::createReader($inFileType);
      $objXL = $objReader->load($targetFile);
    } catch (Exception $e) {
      die("Error opening file: ".$e->getMessage());
    }

    // We're good, so now get the top row so that we can map it out to fields

    $content = "<h3>" . __("Pick the appropriate column header (line 1) for each field name listed below." ) . "</h3>";
    $content .= "<h3>" . __("Mouse over each field for help text.") . "</h3>";

    $content .= '<form method="POST">
                    <input type="hidden" name="stage" value="process">
                    <div class="table">';

    // Find out how many columns are in the spreadsheet so that we can load them as possible values for the fields
    // and we don't really care how many rows there are at this point.
    $sheet = $objXL->getSheet(0);
    $highestColumn = $sheet->getHighestColumn();

    $headerList = $sheet->rangeToArray('A1:' . $highestColumn . '1' );

    $fieldList = array( "None" );
    foreach( $headerList[0] as $fName ) {
      $fieldList[] = $fName;
    }

    $fieldNum = 1;

    foreach ( array( "DataCenter"=>"The unique name of the data center to add the cabinets to.", "Label"=>"The name that you wish to assign to the cabinet being imported.  Typically this is a location name.", "Owner"=>"The unique name of the Department to assign this cabinet to.  You may leave blank for General Use cabinets.", "Zone"=>"The name of an existing zone to place this cabinet within.  The combination of Data Center + Zone must be unique.  Optional.", "Row"=>"The name of an existing row to place this cabinet within.  The combination of Data Center + Row must be unique.  Optional.", "Height"=>"The height, in standard Rack Units (RU), of the cabinet.", "Model"=>"Optional, free form text to describe the model of the cabinet.", "U1Postion"=>"Field indicating the orientation of the cabinet by stating the location of the first U marker.  Valid values are Top, Bottom, or blank (defaults to Bottom).", "MaxkW"=>"A floating point number to indicate the maximum kW allowed to be placed within this cabinet, based upon site criteria.  Optional.", "MaxWeight"=>"An integer indicating the maximum weight (will assume site defined units) that may be placed within this cabinet.  Optional, but strongly encouraged to be set with correct numbers.", "MapX1"=>"Left edge (based on map orientation) of the cabinet zone on the drawing.  Optional.", "MapX2"=>"Right edge (based on map orientation) of the cabinet zone on the drawing.  Optional.", "MapY1"=>"Top edge (based on map orientation) of the cabinet zone on the drawing.  Optional.", "MapY2"=>"Bottom edge (based on map orientation) of the cabinet zone on the drawing.  Optional.", "FrontEdge"=>"Indicator of which edge air intake comes from, which is used to determine row orientation.  Valid values are Top, Bottom, Left, Right, or blank (will assume Top)." ) as $fieldName=>$helpText ) {
      $content .= '<div>
                    <div><span title="' . __($helpText) . '">' . __($fieldName) . '</span>: </div><div><select name="' . $fieldName . '">';
      for ( $n = 0; $n < sizeof( $fieldList ); $n++ ) {
        if ( $n == $fieldNum )
            $selected = "SELECTED";
        else
            $selected = "";

        $content .= "<option value=$n $selected>$fieldList[$n]</option>\n";
      }

      $content .= '</select>
                    </div>
                  </div>';

      $fieldNum++;
    }

    $content .= "<div><div></div><div><input type='submit' value='" . __("Process") . "' name='submit'></div></div>";

    $content .= '</form>
        </div>';
  } elseif ( isset($_REQUEST['stage']) && $_REQUEST['stage'] == 'process' ) {
    // This is much simpler than the bulk device import, so there is no Validate stage
    // so instead we just ask for what the key value fields are and then try to make matches.
    // Any that we can't find a unique match for get printed out as errors.
    //

    $targetFile = $_SESSION['inputfile'];
    try {
      $inFileType = PHPExcel_IOFactory::identify($targetFile);
      $objReader = PHPExcel_IOFactory::createReader($inFileType);
      $objXL = $objReader->load($targetFile);
    } catch (Exception $e) {
      die("Error opening file: ".$e->getMessage());
    }

    // Start off with the assumption that we have zero processing errors
    $errors = false;

    $sheet = $objXL->getSheet(0);
    $highestRow = $sheet->getHighestRow();

    // Make some quick arrays of the MediaType and ColorCoding tables - they are small and can easily fit in memory
    $st = $dbh->prepare( "select * from fac_ColorCoding" );
    $st->execute();
    $colors = array();
    while ( $row = $st->fetch() ) {
      $colors[strtoupper($row['Name'])] = $row['ColorID'];
    }

    $st = $dbh->prepare( "select * from fac_MediaTypes" );
    $st->execute();
    $media = array();
    while ( $row = $st->fetch() ) {
      $media[strtoupper($row['MediaType'])] = $row['MediaID'];
    }

    // Also make sure we start with an empty string to display
    $content = "";
    $fields = array( "DataCenter", "Label", "Owner", "Zone", "Row", "Height", "Model", "U1Position", "MaxkW", "MaxWeight", "MapX1", "MapX2", "MapY1", "MapY2", "FrontEdge" );

    for ( $n = 2; $n <= $highestRow; $n++ ) {
      $rowError = false;

      $cab = new Cabinet();
 
      // Load up the $row[] array with the values according to the mapping supplied by the user
      foreach( $fields as $fname ) {
        $addr = chr( 64 + $_REQUEST[$fname]);
        $row[$fname] = sanitize($sheet->getCell( $addr . $n )->getValue());
      }

      /*
       *
       *  Section for looking up the DataCenter and setting the true DataCenterID
       *
       */
      $st = $dbh->prepare( "select count(DataCenterID) as TotalMatches, DataCenterID from fac_DataCenter where ucase(Name)=ucase(:DataCenter)" );
      $st->execute( array( ":DataCenter"=>$row["DataCenter"] ));
      if ( ! $val = $st->fetch() ) {
        $info = $dbh->errorInfo();
        error_log( "PDO Error: {$info[2]}");
      }

      if ( $val["TotalMatches"] == 1 ) {
        $cab->DataCenterID = $val["DataCenterID"];
      } else {
        $errors = true;
        $content .= "<li>Cabinet: DataCenter = " . $row["DataCenter"] . " is not unique or not found.";
      }

      /*
       *
       *  Section for looking up the ZoneID and setting the true ZoneID in the cab variable
       *
       */
      if ( $row["Zone"] != "" && $cab->DataCenterID > 0 ) {
        $st = $dbh->prepare( "select count(ZoneID) as TotalMatches, ZoneID from fac_Zone where ucase(" . $idField . ")=ucase(:TargetDeviceID)" );
        $st->execute( array( ":TargetDeviceID"=>$row["TargetDeviceID"] ));
        if ( ! $val = $st->fetch() ) {
          $info = $dbh->errorInfo();
          error_log( "PDO Error: {$info[2]}");
        }
      }

      if ( $row["Zone"]!="" && $val["TotalMatches"] == 1 ) {
        $cab->ZoneID = $val["ZoneID"];
      } elseif ($row["Zone"]!="" ) {
        $errors = true;
        $content .= "<li>Cabinet: Data Center + Zone = " . $row["Zone"] . " is not unique or not found.";
      }

      /*
       *
       *  Section for looking up the SourcePort by name and setting the true PortNumber in the devPort variable
       *
       */
      $st = $dbh->prepare( "select count(*) as TotalMatches, Label, PortNumber from fac_Ports where DeviceID=:DeviceID and PortNumber>0 and ucase(Label)=ucase(:SourcePort)" );
      $st->execute( array( ":DeviceID"=>$devPort->DeviceID, ":SourcePort"=>$row["SourcePort"] ));
      if ( ! $val = $st->fetch() ) {
        $info = $dbh->errorInfo();
        error_log( "PDO Error: {$info[2]}");
      }

      if ( $val["TotalMatches"] == 1 ) {
        $devPort->PortNumber = $val["PortNumber"];
        $devPort->Label = $val["Label"];
      } else {
        $errors = true;
        $content .= "<li>Source Port: " . $row["SourcePort"] . " is not unique or not found.";
      }

      /*
       *
       *  Section for looking up the TargetPort by name and setting the true PortNumber in the devPort variable
       *  Limits to positive port numbers so that you can match Patch Panel frontside ports
       *
       */
      $st = $dbh->prepare( "select count(*) as TotalMatches, Label, PortNumber from fac_Ports where DeviceID=:DeviceID and PortNumber>0 and ucase(Label)=ucase(:TargetPort)" );
      $st->execute( array( ":DeviceID"=>$devPort->ConnectedDeviceID, ":TargetPort"=>$row["TargetPort"] ));
      if ( ! $val = $st->fetch() ) {
        $info = $dbh->errorInfo();
        error_log( "PDO Error: {$info[2]}");
      }

      if ( $val["TotalMatches"] == 1 ) {
        $devPort->ConnectedPort = $val["PortNumber"];
      } else {
        $errors = true;
        $content .= "<li>Target Port: " . $row["TargetDeviceID"] . "::" . $row["TargetPort"] . " is not unique or not found.";
      }

      // Do not fail if the Color Code or Media Type are not defined for the site.
      if ( $row["MediaType"] != "" ) {
        $devPort->MediaID = @$media[strtoupper($row["MediaType"])];
      }

      if ( $row["ColorCode"] != "" ) {
        $devPort->ColorID = @$colors[strtoupper($row["ColorCode"])];
      }

      $devPort->Notes = $row["Notes"];

      if ( ! $rowError ) {
        if ( ! $devPort->updatePort() ) {
          $errors = true;
        }
      } else {
        $errors = true;
      }

      if ( $rowError ) {
        $content .= "<li><strong>Error making port connection on Row $n of the spreadsheet.</strong>";
      }
    }

    if ( ! $errors ) {
      $content = __("All records imported successfully.") . "<ul>" . $content . "</ul>";
    } else {
      $content = __("At least one error was encountered processing the file.  Please see below.") . "<ul>" . $content . "</ul>";
    }
  } else {
    //
    //  No parameters were passed with the URL, so this is the top level, where
    //  we need to ask for the user to specify a file to upload.
    //
    $content = '<form method="POST" ENCTYPE="multipart/form-data">';
    $content .= '<div class="table">
                  <div>
                    <div>' . __("Select file to upload:") . '
                    <input type="file" name="inputfile" id="inputfile">
                    </div>
                  </div>
                  <div>
                    <div>
                    <input type="submit" value="Upload" name="submit">
                    </div>
                  </div>
                  </div>
                  </form>
                  </div>';

  }


  //
  //  Render the page with the main section being whatever has been loaded into the
  //  variable $content - every stage spills out to here other than the file upload
  //
?>
<!doctype html>
<html>
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  
  <title>openDCIM</title>
  <link rel="stylesheet" href="css/inventory.php" type="text/css">
  <link rel="stylesheet" href="css/jquery-ui.css" type="text/css">
  <!--[if lt IE 9]>
  <link rel="stylesheet"  href="css/ie.css" type="text/css" />
  <![endif]-->
  
  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
</head>
<body>
<?php include( 'header.inc.php' ); ?>
<div class="page index">
<?php
	include( 'sidebar.inc.php' );
?>
<div class="main">
<div class="center"><div>

<?php
  echo $content;
?>

<!-- CONTENT GOES HERE -->



</div></div>
</div><!-- END div.main -->
</div><!-- END div.page -->
</body>
</html>
