<?php
// Run with WordPress loaded, e.g. wp eval-file test-title.php.
$cases = [
 ['Coestra – Political Party & Candidate Elementor Template Kit','Coestra – Political Party &amp; Candidate Elementor Template Kit',true],
 ['A &amp; B','A &amp; B',true],
 ['A &amp; B','A &amp;amp; B',false],
 ['A & B','A & C',false],
 ['A & B','A  & B',false],
 ['<b>A</b> & B','A &amp; B',false],
 ['A & B','A &amp; B extra',false],
 ['A & B','a &amp; B',false],
];
foreach($cases as [$expected,$actual,$wanted]){
 if(odlContentPostTitleMatches($actual,$expected)!==$wanted)throw new RuntimeException('Title verification mismatch: '.$actual);
}
echo "8 title verification cases passed\n";
