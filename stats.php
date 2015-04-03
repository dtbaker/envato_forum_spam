<?php


$data = @json_decode(file_get_contents('data.json'),true);

?>

<h1>Automatically Disabled Threads So Far:</h1>

<ul>
	<?php foreach($data['disabled'] as $forum_id => $d){ ?>
	<li>
		<?php echo date('Y-m-d H:i:s',$d[0]);?> - <a href="<?php echo $d[1];?>" target="_blank"><?php echo $d[1];?></a>
	</li>
	<?php } ?>
</ul>