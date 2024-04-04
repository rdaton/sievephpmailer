#!/bin/bash

sleepSeconds=900 # 15 minutes
#runs at 0m, 15m, 30m, 45m
for((i=0;i<3;i++))
do
bash ./cadaMinuto.sh
sleep $sleepSeconds
done


