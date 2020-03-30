const express=require('express')

const app=express();
const port=3000;

app.get('/',function(req,res){
    res.sendfile("index.html");
  });

app.get('/home',function(req,res){
    res.json("data");
})

app.listen(port,()=>{console.log(`listento port 3000 ......`)});