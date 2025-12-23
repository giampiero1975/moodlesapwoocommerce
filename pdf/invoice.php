<?php
require('fpdf.php');
define('EURO', chr(128) );
define('EURO_VAL', 6.55957 );

// Xavier Nicolay 2004
// Version 1.03

//////////////////////////////////////
// Public functions                 //
//////////////////////////////////////
//  function sizeOfText( $texte, $larg )
//  function addSociete( $nom, $adresse )
//  function fact_dev( $libelle, $num )
//  function addDevis( $numdev )
//  function addFacture( $numfact )
//  function addDate( $date )
//  function addClient( $ref )
//  function addPageNumber( $page )
//  function addClientAdresse( $adresse )
//  function addReglement( $mode )
//  function addEcheance( $date )
//  function addNumTVA($tva)
//  function addReference($ref)
//  function addCols( $tab )
//  function addLineFormat( $tab )
//  function lineVert( $tab )
//  function addLine( $ligne, $tab )
//  function addRemarque($remarque)
//  function addCadreTVAs()
//  function addCadreEurosFrancs()
//  function addTVAs( $params, $tab_tva, $invoice )
//  function temporaire( $texte )
#[\AllowDynamicProperties]
class PDF_Invoice extends FPDF
{
// private variables
var $colonnes;
var $format;
var $angle=0;

function logo($naz=null){
    
    $this->naz = $naz;
    // personalizzazioni lingua e logo
    switch($this->naz){
        case 'es':
            // Logo
            $this->logo = "/logoEs.png";
            $this->footer['0'] ="Marketing &Telematica España S.L";
            $this->footer['1'] ="(Sociedad Unipersonal)";
            $this->footer['2'] ="Carrer Marqués de Sentmenat, 54 - 08029 Barcelona (BAR)";
            $this->footer['3'] ="Tel. +34 934 452 810 - Fax +34 934 452 817";
            $this->footer['4'] ="info@metba.es - www.metba.es";
            $this->footer['5'] ="N.I.F. B-65064842";
            $this->footer['6'] ="Inscrita en el R.M. de Barcelona";
            $this->footer['7'] ="Tomo 41126, Folio 29, Hoja B - 379910, inscripción 1ª";
            $this->footer['8'] ="";
            break;
        case 'it':
            // Logo
            $this->logo = "/logo.png";
            $this->footer['0'] ="Metmi S.r.l.";
            $this->footer['1'] ="Sede legale: Strada della Moia 1 - 20044 Arese (MI)";
            $this->footer['2'] ="Sede operativa: Strada della Moia 1 - 20044 Arese (MI)";
            $this->footer['3'] ="Tel 02/380731 Fax 02/38073208";
            $this->footer['4'] ="www.metmi.it info@metmi.it";
            $this->footer['5'] ="Capitale Sociale 10.000,00 i.v.";
            $this->footer['6'] ="Codice Fiscale/Partita IVA 10432930963";
            $this->footer['7'] ="Registro imprese Milano Monza Brianza Lodi n.10432930963";
            $this->footer['8'] ="R.E.A. MI - 2531180";
            break;
        default:
            die('Nazione invoice setting PDF non presente');
            break;
    }
    // Logo
    $this->Image(__DIR__ . $this->logo,80,6,40);
    // Line break
    $this->Ln(20);
}

// private functions
function RoundedRect($x, $y, $w, $h, $r, $style = '')
{
	$k = $this->k;
	$hp = $this->h;
	if($style=='F')
		$op='f';
	elseif($style=='FD' || $style=='DF')
		$op='B';
	else
		$op='S';
	$MyArc = 4/3 * (sqrt(2) - 1);
	$this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
	$xc = $x+$w-$r ;
	$yc = $y+$r;
	$this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));

	$this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
	$xc = $x+$w-$r ;
	$yc = $y+$h-$r;
	$this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
	$this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
	$xc = $x+$r ;
	$yc = $y+$h-$r;
	$this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
	$this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
	$xc = $x+$r ;
	$yc = $y+$r;
	$this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
	$this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
	$this->_out($op);
}

function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
{
	$h = $this->h;
	$this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1*$this->k, ($h-$y1)*$this->k,
						$x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
}

function Rotate($angle, $x=-1, $y=-1)
{
	if($x==-1)
		$x=$this->x;
	if($y==-1)
		$y=$this->y;
	if($this->angle!=0)
		$this->_out('Q');
	$this->angle=$angle;
	if($angle!=0)
	{
		$angle*=M_PI/180;
		$c=cos($angle);
		$s=sin($angle);
		$cx=$x*$this->k;
		$cy=($this->h-$y)*$this->k;
		$this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',$c,$s,-$s,$c,$cx,$cy,-$cx,-$cy));
	}
}

function _endpage()
{
	if($this->angle!=0)
	{
		$this->angle=0;
		$this->_out('Q');
	}
	parent::_endpage();
}

// public functions
function sizeOfText( $texte, $largeur )
{
	$index    = 0;
	$nb_lines = 0;
	$loop     = TRUE;
	while ( $loop )
	{
		$pos = strpos($texte, "\n");
		if (!$pos)
		{
			$loop  = FALSE;
			$ligne = $texte;
		}
		else
		{
			$ligne  = substr( $texte, $index, $pos);
			$texte = substr( $texte, $pos+1 );
		}
		$length = floor( $this->GetStringWidth( $ligne ) );
		$res = 1 + floor( $length / $largeur) ;
		$nb_lines += $res;
	}
	return $nb_lines;
}

// Company
function addSociete( $nom, $adresse )
{
	$x1 = 10;
	$y1 = 30;
	//Positionnement en bas
	$this->SetXY( $x1, $y1 );
	$this->SetFont('Arial','B',12);
	$length = $this->GetStringWidth( $nom );
	$this->Cell( $length, 2, $nom);
	$this->SetXY( $x1, $y1 + 4 );
	$this->SetFont('Arial','',10);
	$length = $this->GetStringWidth( $adresse );
	//Coordonnées de la société
	# $lignes = $this->sizeOfText( $adresse, $length) ;
	$this->MultiCell($length, 4, $adresse);
}

function datifatt($cardname,$piva, $cf )
{
    $x1 = 10;
    $y1 = 60;
    //Positionnement en bas
    $this->SetXY( $x1, $y1 );
    $this->SetFont('Arial','',10);
    $length = $this->GetStringWidth( $cardname );
    $this->Cell( $length, 2, $cardname);
    
    $this->SetXY( $x1, $y1 + 4 );
    $this->SetFont('Arial','',10);
    $length = $this->GetStringWidth( $piva );
    $this->Cell( $length, 2, $piva);
    
    $this->SetXY( $x1, $y1 + 8 );
    $this->SetFont('Arial','',10);
    $length = $this->GetStringWidth( $cf );
    $this->Cell( $length, 2, $cf);
}

function addShip($adresse )
{
    $x1 = ($this->GetPageWidth()-80);
    $y1 =40;
    //Positionnement en bas
    $this->SetXY( $x1, $y1 );
    $this->SetFont('Arial','',10);
    $length = $this->GetStringWidth( $adresse );
    $length = 70;
    //Coordonnées de la société
    //$lignes = $this->sizeOfText( $adresse, $length) ;
    $this->MultiCell($length, 4, $adresse);
}

// Label and number of invoice/estimate
function fact_dev( $libelle, $num )
{
    $r1  = $this->w - 80;
    $r2  = $r1 + 68;
    $y1  = 30;
    $y2  = $y1 + 2;
    $mid = ($r1 + $r2 ) / 2;
    
    #$texte  = $libelle . " EN " . EURO . " N° : " . $num;
    $texte  = $libelle . " " . $num;
    
    $szfont = 12;
    $loop   = 0;
    
    while ( $loop == 0 )
    {
       $this->SetFont( "Arial", "B", $szfont );
       $sz = $this->GetStringWidth( $texte );
       if ( ($r1+$sz) > $r2 )
          $szfont --;
       else
          $loop ++;
    }

    $this->SetLineWidth(0.1);
    $this->SetFillColor(192);
    $this->RoundedRect($r1, $y1, ($r2 - $r1), 9, 2.5, 'DF');
    $this->SetXY( $r1+1, $y1+2);
    $this->Cell($r2-$r1 -1,5, $texte, 0, 0, "C" );
}

// Estimate
function addDevis( $numdev )
{
	$string = sprintf("DEV%04d",$numdev);
	$this->fact_dev( "Devis", $string );
}

// Invoice
function addFacture( $numfact )
{
	$string = sprintf("FA%04d",$numfact);
	$this->fact_dev( "Facture", $string );
}

function addDate( $date )
{
	$r1  = $this->w - 61;
	$r2  = $r1 + 30;
	$y1  = 17;
	$y2  = $y1 ;
	$mid = $y1 + ($y2 / 2);
	$this->RoundedRect($r1, $y1, ($r2 - $r1), $y2, 3.5, 'D');
	$this->Line( $r1, $mid, $r2, $mid);
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5, $y1+3 );
	$this->SetFont( "Arial", "B", 10);
	$this->Cell(10,5, "DATE", 0, 0, "C");
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5, $y1+9 );
	$this->SetFont( "Arial", "", 10);
	$this->Cell(10,5,$date, 0,0, "C");
}

function addClient( $ref )
{
	$r1  = $this->w - 31;
	$r2  = $r1 + 19;
	$y1  = 17;
	$y2  = $y1;
	$mid = $y1 + ($y2 / 2);
	$this->RoundedRect($r1, $y1, ($r2 - $r1), $y2, 3.5, 'D');
	$this->Line( $r1, $mid, $r2, $mid);
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5, $y1+3 );
	$this->SetFont( "Arial", "B", 10);
	$this->Cell(10,5, "CLIENT", 0, 0, "C");
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5, $y1 + 9 );
	$this->SetFont( "Arial", "", 10);
	$this->Cell(10,5,$ref, 0,0, "C");
}

function addPageNumber( $page )
{
	$r1  = $this->w - 80;
	$r2  = $r1 + 19;
	$y1  = 17;
	$y2  = $y1;
	$mid = $y1 + ($y2 / 2);
	$this->RoundedRect($r1, $y1, ($r2 - $r1), $y2, 3.5, 'D');
	$this->Line( $r1, $mid, $r2, $mid);
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5, $y1+3 );
	$this->SetFont( "Arial", "B", 10);
	$this->Cell(10,5, "PAGE", 0, 0, "C");
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5, $y1 + 9 );
	$this->SetFont( "Arial", "", 10);
	$this->Cell(10,5,$page, 0,0, "C");
}

// Client address
function addClientAdresse( $adresse )
{
	$r1     = $this->w - 80;
	$r2     = $r1 + 68;
	$y1     = 40;
	$this->SetXY( $r1, $y1);
	$this->MultiCell( 60, 4, $adresse);
}

// Mode of payment
function addReglement( $mode )
{
	$r1  = 10;
	$r2  = $r1 + 60;
	$y1  = 80;
	$y2  = $y1+10;
	$mid = $y1 + (($y2-$y1) / 2);
	$this->RoundedRect($r1, $y1, ($r2 - $r1), ($y2-$y1), 2.5, 'D');
	$this->Line( $r1, $mid, $r2, $mid);
	$this->SetXY( $r1 + ($r2-$r1)/2 -5 , $y1+1 );
	$this->SetFont( "Arial", "B", 10);
	$this->Cell(10,4, "MODE DE REGLEMENT", 0, 0, "C");
	$this->SetXY( $r1 + ($r2-$r1)/2 -5 , $y1 + 5 );
	$this->SetFont( "Arial", "", 10);
	$this->Cell(10,5,$mode, 0,0, "C");
}

// Expiry date
function addEcheance( $date )
{
	$r1  = 80;
	$r2  = $r1 + 40;
	$y1  = 80;
	$y2  = $y1+10;
	$mid = $y1 + (($y2-$y1) / 2);
	$this->RoundedRect($r1, $y1, ($r2 - $r1), ($y2-$y1), 2.5, 'D');
	$this->Line( $r1, $mid, $r2, $mid);
	$this->SetXY( $r1 + ($r2 - $r1)/2 - 5 , $y1+1 );
	$this->SetFont( "Arial", "B", 10);
	$this->Cell(10,4, "DATE D'ECHEANCE", 0, 0, "C");
	$this->SetXY( $r1 + ($r2-$r1)/2 - 5 , $y1 + 5 );
	$this->SetFont( "Arial", "", 10);
	$this->Cell(10,5,$date, 0,0, "C");
}

// VAT number
function addNumTVA($tva)
{
	$this->SetFont( "Arial", "B", 10);
	$r1  = $this->w - 80;
	$r2  = $r1 + 70;
	$y1  = 80;
	$y2  = $y1+10;
	$mid = $y1 + (($y2-$y1) / 2);
	$this->RoundedRect($r1, $y1, ($r2 - $r1), ($y2-$y1), 2.5, 'D');
	$this->Line( $r1, $mid, $r2, $mid);
	$this->SetXY( $r1 + 16 , $y1+1 );
	$this->Cell(40, 4, "TVA Intracommunautaire", '', '', "C");
	$this->SetFont( "Arial", "", 10);
	$this->SetXY( $r1 + 16 , $y1+5 );
	$this->Cell(40, 5, $tva, '', '', "C");
}

function addReference($ref)
{
	$this->SetFont( "Arial", "", 10);
	$length = $this->GetStringWidth( "Références : " . $ref );
	$r1  = 10;
	$r2  = $r1 + $length;
	$y1  = 92;
	$y2  = $y1+5;
	$this->SetXY( $r1 , $y1 );
	$this->Cell($length,4, "Références : " . $ref);
}

function addCols( $tab )
{
	global $colonnes;
	
	$r1  = 10;
	$r2  = $this->w - ($r1 * 2) ;
	$y1  = 100;
	#$y2  = $this->h - 50 - $y1;
	$y2  = $this->h - 150 - $y1;
	
	$this->SetXY( $r1, $y1 );
	$this->Rect( $r1, $y1, $r2, $y2, "D");
	$this->Line( $r1, $y1+6, $r1+$r2, $y1+6);
	$colX = $r1;
	$colonnes = $tab;
	foreach ( $tab as $lib => $pos )
	{
		$this->SetXY( $colX, $y1+2 );
		$this->Cell( $pos, 1, $lib, 0, 0, "C");
		$colX += $pos;
		$this->Line( $colX, $y1, $colX, $y1+$y2);
	}
}

function addLineFormat( $tab )
{
	global $format, $colonnes;
	
	foreach ( $colonnes as $lib => $pos )
	{
		if ( isset( $tab["$lib"] ) )
			$format[ $lib ] = $tab["$lib"];
	}
}

function lineVert( $tab )
{
	global $colonnes;

	$maxSize=0;
	foreach ( $colonnes as $lib => $pos )
	{
		$texte = $tab[ $lib ];
		$longCell  = $pos -2;
		$size = $this->sizeOfText( $texte, $longCell );
		if ($size > $maxSize)
			$maxSize = $size;
	}
	return $maxSize;
}

function addLine( $ligne, $tab )
{
    /*
    echo "<pre>";
    print_r($ligne);
    print_r($tab);
    */
	global $colonnes, $format;

	$ordonnee     = 10;
	$maxSize      = $ligne;

	foreach ( $colonnes as $lib => $pos )
	{
		$longCell  = $pos -2;
		$texte     = $tab[ $lib ];
		$length    = $this->GetStringWidth( $texte );
		$tailleTexte = $this->sizeOfText( $texte, $length );
		$formText  = $format[ $lib ];
		$this->SetXY( $ordonnee, $ligne-1);
		$this->MultiCell( $longCell, 4 , $texte, 0, $formText);
		if ( $maxSize < ($this->GetY()  ) )
			$maxSize = $this->GetY() ;
		$ordonnee += $pos;
	}
	return ( $maxSize - $ligne );
}

function addRemarque($remarque)
{
	$this->SetFont( "Arial", "", 10);
	$length = $this->GetStringWidth( "Remarque : " . $remarque );
	$r1  = 10;
	$r2  = $r1 + $length;
	$y1  = $this->h - 45.5;
	$y2  = $y1+5;
	$this->SetXY( $r1 , $y1 );
	$this->Cell($length,4, "Remarque : " . $remarque);
}

function addCadreTVAs()
{
	$this->SetFont( "Arial", "B", 8);
	$r1  = 10;
	$r2  = $r1 + 140;
	$y1  = $this->h - 140;
	$y2  = $y1+20;
	$this->RoundedRect($r1, $y1, ($r2 - $r1), ($y2-$y1), 2.5, 'D');
	$this->Line( $r1, $y1+4, $r2, $y1+4);
	$this->Line( $r1+27, $y1, $r1+27, $y2);  // IVA
	$this->Line( $r1+43, $y1, $r1+43, $y2);  // TOT IVA
	$this->Line( $r1+63, $y1, $r1+63, $y2);  // CODICE IVA
	$this->Line( $r1+115, $y1, $r1+115, $y2);  // avant TOTAUX
	$this->SetXY( $r1+5, $y1);
	$this->Cell(10,4, "IMPONIBILE");
	$this->SetX( $r1+31 );
	$this->Cell(10,4, "IVA");
	$this->SetX( $r1+46 );
	$this->Cell(10,4, "TOT IVA");
	$this->SetX( $r1+67 );
	$this->Cell(10,4, "CODICE IVA");
	$this->SetX( $r1+120 );
	$this->Cell(10,4, "TOTALE");
}

function addCadreEurosFrancs()
{
	$r1  = $this->w - 70;
	$r2  = $r1 + 60;
	$y1  = $this->h - 40;
	$y2  = $y1+20;
	$this->RoundedRect($r1, $y1, ($r2 - $r1), ($y2-$y1), 2.5, 'D');
	$this->Line( $r1+20,  $y1, $r1+20, $y2); // avant EUROS
	$this->Line( $r1+20, $y1+4, $r2, $y1+4); // Sous Euros & Francs
	$this->Line( $r1+38,  $y1, $r1+38, $y2); // Entre Euros & Francs
	$this->SetFont( "Arial", "B", 8);
	$this->SetXY( $r1+22, $y1 );
	$this->Cell(15,4, "EUROS", 0, 0, "C");
	$this->SetFont( "Arial", "", 8);
	$this->SetXY( $r1+42, $y1 );
	$this->Cell(15,4, "FRANCS", 0, 0, "C");
	$this->SetFont( "Arial", "B", 6);
	$this->SetXY( $r1, $y1+5 );
	$this->Cell(20,4, "TOTAL TTC", 0, 0, "C");
	$this->SetXY( $r1, $y1+10 );
	$this->Cell(20,4, "ACOMPTE", 0, 0, "C");
	$this->SetXY( $r1, $y1+15 );
	$this->Cell(20,4, "NET A PAYER", 0, 0, "C");
}

function addTVAs1( $invoice ){
    #echo "<pre>";
    #print_r($invoice);
    $importo=array();
    $importoTot = 0;
    foreach ($invoice as $keiInv=>$valueInv){
        $importo[$keiInv]['imponibile']=$valueInv['imponibile'];
        $importo[$keiInv]['iva']=$valueInv['iva'];
        $importo[$keiInv]['perciva']=($valueInv['imponibile']*$valueInv['iva'])/100;
        $importo[$keiInv]['codiva']=$valueInv['codiva'];
        $importo[$keiInv]['importo'] = $importo[$keiInv]['imponibile']+$importo[$keiInv]['perciva'];
        $importoTot += $importo[$keiInv]['importo'];
    }
    # echo "<pre>";
    # print_r($importo);
    #echo "<br> >> ".$importoTot;
    
    $this->SetFont('Arial','',10);
    $y = 158;
    foreach ( $importo as $kimporto => $vimporto)
    {
        $y += 4;
        $x = 14;
        $this->SetXY($x, $y);
        $this->Cell( 20,4, $vimporto['imponibile'],'', '','R' );
        
        $this->SetXY($x+28, $y);
        $this->Cell( $this->GetStringWidth($vimporto['iva']),4, $vimporto['iva'],'', '','L' );
        
        $this->SetXY($x+60, $y);
        $this->Cell( $this->GetStringWidth($vimporto['codiva']),4, $vimporto['codiva'],'', '','L' );
        
        $this->SetXY($x+47, $y);
        $this->Cell( $this->GetStringWidth($vimporto['perciva']),4, sprintf("%0.2F", $vimporto['perciva']),'', '','L' );
        
        $this->SetXY($x+110, $y);
        $this->Cell( 20,4, sprintf("%0.2F", $vimporto['importo']),'', '','R' );
    }
    
    $this->SetXY($x+110, $y+4);
    $this->SetFont('Arial', 'B', 12);
    $this->Cell( 20,8, sprintf("%0.2F", $importoTot),'', '','R' );
}

// add a watermark (temporary estimate, DUPLICATA...)
// call this method first

function temporaire( $texte )
{
	$this->SetFont('Arial','B',50);
	$this->SetTextColor(203,203,203);
	$this->Rotate(45,55,190);
	$this->Text(55,190,$texte);
	$this->Rotate(0);
	$this->SetTextColor(0,0,0);
}

function Footer()
{
    $y1 = -30;
    $x1 = 40;
    $x2 = -100;
    
    $this->Line(3, ($this->GetPageHeight()-35), ($this->GetPageWidth()-3), ($this->GetPageHeight()-35));
    
    //Positionnement en bas et tout centrer
    $this->SetXY( 10, $y1 );
    $this->SetFont('Arial','',10);
    $this->Cell( $x1, 0, $this->footer['0'], 0, 0, 'L');
    
    $this->SetXY( 10, $y1 + 5 );
    $this->Cell( $x1, 0, $this->footer['1'], 0, 0, 'L');
    
    $this->SetXY( 10, $y1 + 10 );
    $this->Cell( $x1, 0, $this->footer['2'], 0, 0, 'L');
    
    $this->SetXY( 10, $y1 + 15 );
    $this->Cell( $x1, 0, $this->footer['3'], 0, 0, 'L');
    
    $this->SetXY( 10, $y1 + 20 );
    $this->Cell( $x1, 0, $this->footer['4'], 0, 0, 'L');
    
    $this->SetXY( $x2, $y1 );
    $this->Cell( $x1, 0, $this->footer['5'], 0, 0, 'L');
    
    $this->SetXY( $x2, $y1 + 5 );
    $this->Cell( $x1, 0, $this->footer['6'], 0, 0, 'L');
    
    $this->SetXY( $x2, $y1 + 10 );
    $this->Cell( $x1, 0, $this->footer['7'], 0, 0, 'L');
    
    $this->SetXY( $x2, $y1 + 15 );
    $this->Cell( $x1, 0, $this->footer['8'], 0, 0, 'L');
    
    // Position at 1.5 cm from bottom
    $this->SetY(-10);
    // Arial italic 8
    $this->SetFont('Arial','I',8);
    // Page number
    
    #$this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
}
}
?>
