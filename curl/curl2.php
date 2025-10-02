<?php
// inserimento BP

$xml_data = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
                                <soapenv:Header/>
                                    <soapenv:Body>
                                    <loc:BOsync>
                                    <loc:reqType>set</loc:reqType>
                                    <loc:objType></loc:objType>
                                    <loc:docXml>&lt;BusinessPartner&gt;&lt;RequestInfo&gt;
                                    &lt;requestUser&gt;manager&lt;/requestUser&gt;
                                    &lt;requestDataTime&gt;2023-05-11 03:05:53&lt;/requestDataTime&gt;
                                    &lt;requestDB&gt;METMI_TEST&lt;/requestDB&gt;
                                &lt;/RequestInfo&gt;&lt;Data&gt;
                                &lt;codice&gt;CM003796&lt;/codice&gt;
                                &lt;ragsoc&gt;peczsz alina&lt;/ragsoc&gt;
                                &lt;conto&gt;14001010&lt;/conto&gt;
                                &lt;tiposoll&gt;&lt;/tiposoll&gt;
                                &lt;tipoimp&gt;&lt;/tipoimp&gt;
                                &lt;codiva&gt;&lt;/codiva&gt;
                                &lt;cat&gt;&lt;/cat&gt;
                                &lt;zona&gt;&lt;/zona&gt;
                                &lt;valuta&gt;EUR&lt;/valuta&gt;
                                &lt;codage&gt;-1&lt;/codage&gt;
                                &lt;piva&gt;IT10105161219&lt;/piva&gt;
                                &lt;codfis&gt;PCZLNA81M71Z127S&lt;/codfis&gt;
                                &lt;singlepay&gt;N&lt;/singlepay&gt;
                                &lt;tel1&gt;393791373569&lt;/tel1&gt;
                                &lt;tel2&gt;&lt;/tel2&gt;
                                &lt;fax&gt;&lt;/fax&gt;
                                &lt;email&gt;alina.kozak@gmail.com&lt;/email&gt;
                                &lt;web&gt;&lt;/web&gt;
                                &lt;lingua&gt;13&lt;/lingua&gt;
                                &lt;tipobp&gt;CBI&lt;/tipobp&gt;
                                &lt;bloccopag&gt;N&lt;/bloccopag&gt;
                                &lt;numlettes&gt;&lt;/numlettes&gt;
                                &lt;impes&gt;&lt;/impes&gt;
                                &lt;dtiniese&gt;&lt;/dtiniese&gt;
                                &lt;dtfinese&gt;&lt;/dtfinese&gt;
                                &lt;impfido&gt;&lt;/impfido&gt;
                                &lt;annullato&gt;N&lt;/annullato&gt;
                                &lt;noteana&gt;&lt;/noteana&gt;
                                &lt;abiint&gt;08374&lt;/abiint&gt;
                                &lt;nazint&gt;IT&lt;/nazint&gt;
                                &lt;cabint&gt;32480&lt;/cabint&gt;
                                &lt;ccint&gt;000000113266&lt;/ccint&gt;
                                &lt;codpag&gt;&lt;/codpag&gt;
                                &lt;sapproperty&gt;&lt;/sapproperty&gt;
                                &lt;intra&gt;N&lt;/intra&gt;
                                &lt;tipoes&gt;I&lt;/tipoes&gt;
                                &lt;indirpec&gt;&lt;/indirpec&gt;
                                &lt;coddestsdi&gt;000000&lt;/coddestsdi&gt;
                                &lt;CheckPA&gt;N&lt;/CheckPA&gt;
                                &lt;codop347&gt;&lt;/codop347&gt;
                                &lt;opassic347&gt;N&lt;/opassic347&gt;
                                &lt;ritacc&gt;N&lt;/ritacc&gt;
                                &lt;Conguaglio&gt;N&lt;/Conguaglio&gt;
                            	&lt;settore&gt;-1&lt;/settore&gt;
                                &lt;userfield&gt;&lt;/userfield&gt;
                                &lt;indirizzi&gt;
                                  &lt;indirizzo&gt;
                                    &lt;tipoind&gt;B&lt;/tipoind&gt;
                                    &lt;idind&gt;peczsz alina&lt;/idind&gt;
                                    &lt;viaind&gt;VIA VECCHIA DELLE VIGNE 15&lt;/viaind&gt;
                                    &lt;capind&gt;80078&lt;/capind&gt;
                                    &lt;locind&gt;POZZUOLI&lt;/locind&gt;
                                    &lt;statind&gt;IT&lt;/statind&gt;
                                    &lt;provind&gt;NA&lt;/provind&gt;
                                    &lt;pivaind&gt;IT10105161219&lt;/pivaind&gt;
                                  &lt;/indirizzo&gt;
                                  &lt;indirizzo&gt;
                                    &lt;tipoind&gt;S&lt;/tipoind&gt;
                                    &lt;idind&gt;peczsz alina&lt;/idind&gt;
                                    &lt;viaind&gt;VIA VECCHIE DELLE VIGNE 15&lt;/viaind&gt;
                                    &lt;capind&gt;80078&lt;/capind&gt;
                                    &lt;locind&gt;POZZUOLI&lt;/locind&gt;
                                    &lt;statind&gt;IT&lt;/statind&gt;
                                    &lt;provind&gt;NA&lt;/provind&gt;
                                    &lt;pivaind&gt;&lt;/pivaind&gt;
                                  &lt;/indirizzo&gt;
                                &lt;/indirizzi&gt;
                        &lt;/Data&gt;&lt;/BusinessPartner&gt;</loc:docXml>
                              </loc:BOsync>
                              </soapenv:Body>
                              </soapenv:Envelope>';

$headers = array(
    "POST /wsToSAP/B1Sync.asmx HTTP/1.1",
    "Referer: 192.168.10.44",
    //"User-Agent: Dalvik/1.6.0 (Linux; U; Android 4.4.2; GT-I9505 Build/KOT49H)",
    "Content-Type: text/xml; charset=utf-8",
    "Host: 192.168.10.44",
    "Content-length: ".strlen($xml_data),
    "Expect: 100-continue"
);

$url = 'http://192.168.10.44/wsToSAP/B1Sync.asmx?reqType=get&objType=';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,$url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_data);

$reply = curl_exec($ch);
echo "[".$reply."]";
if (stripos($reply, '<IsError>') == false) {
    echo '<br>errore!';
    die();
}
$ex_1=explode("<IsError>",$reply);
$ex_2=explode("</IsError>",$ex_1[1]);
echo "<br>3: ". $ex_2[0];
?>
