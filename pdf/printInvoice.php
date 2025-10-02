<?php
require('fpdf.php');

class PDF extends FPDF
{
    // Page header
    function Header()
    {
        // Logo
        $this->Image('logo.png',80,6,40);
        
        // Line break
        $this->Ln(20);
    }
    
    // Page footer
    function Footer()
    { 
        
        
        $y1 = -30;
        $x1 = 40;
        $x2 = -100;
        
        $this->Line(3, ($this->GetPageHeight()-35), ($this->GetPageWidth()-3), ($this->GetPageHeight()-35));
        
        //Positionnement en bas et tout centrer
        $this->SetXY( 10, $y1 ); 
        $this->SetFont('Arial','',10);
        $this->Cell( $x1, 0, "Metmi S.r.l.", 0, 0, 'L');
        
        $this->SetXY( 10, $y1 + 5 );
        $this->Cell( $x1, 0, "Sede legale: Strada della Moia 1 - 20044 Arese (MI)", 0, 0, 'L');
        
        $this->SetXY( 10, $y1 + 10 );
        $this->Cell( $x1, 0, "Sede operativa: Strada della Moia 1 - 20044 Arese (MI)", 0, 0, 'L');
        
        $this->SetXY( 10, $y1 + 15 );
        $this->Cell( $x1, 0, "Tel 02/380731 Fax 02/38073208", 0, 0, 'L');
        
        $this->SetXY( 10, $y1 + 20 );
        $this->Cell( $x1, 0, "www.metmi.it info@metmi.it", 0, 0, 'L');
        
        $this->SetXY( $x2, $y1 );
        $this->Cell( $x1, 0, "Capitale Sociale 10.000,00 i.v.", 0, 0, 'L');
        
        $this->SetXY( $x2, $y1 + 5 );
        $this->Cell( $x1, 0, "Codice Fiscale/Partita IVA 10432930963", 0, 0, 'L');
        
        $this->SetXY( $x2, $y1 + 10 );
        $this->Cell( $x1, 0, "Registro imprese Milano Monza Brianza Lodi n.10432930963", 0, 0, 'L');
        
        $this->SetXY( $x2, $y1 + 15 );
        $this->Cell( $x1, 0, "R.E.A. MI - 2531180", 0, 0, 'L');
        
        // Position at 1.5 cm from bottom
        $this->SetY(-10);
        // Arial italic 8
        $this->SetFont('Arial','I',8);
        // Page number
        
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

// Instanciation of inherited class
$pdf = new PDF( 'P', 'mm', 'A4' );
$pdf->SetAutoPageBreak(true, 10);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial','B',12);

$pdf->SetXY( 10, 40);
$pdf->Cell(0,0,'Sede Legale:',0,'L','','');

$pdf->SetFont('Arial','',12);
$pdf->SetXY( 10, 50);
$pdf->Cell(0,0,'xxxxx',0,'L','','');

$pdf->SetFont('Arial','B',12);
$pdf->SetXY(($pdf->GetPageWidth()-90), 40);
$pdf->Cell(0,0,'Fattura di Vendita N°:',0,'L','','');

/*
for($i=1;$i<=40;$i++)
    $pdf->Cell(0,10,'Printing line number '.$i,0,1);
*/
    $pdf->Output();
    ?>