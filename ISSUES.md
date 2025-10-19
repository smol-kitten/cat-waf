# Known Issues:
[X] - Fixed  
[-] - Wont Fix (for now)  
[ ] - In Progress  

# Known Issues List:
- [ ] Rate Limit consistency
- [ ] Fully automatic backup, reset & restore process
- [ ] Auto update for non prebuilt images 
- [ ] rate limits not working as expected 
- [ ] 

# To Test / Verify:
- [ ] Custom ModSecurity rules
- [ ] Auto bans
- [ ] Test if IP Bans work at all
- [ ] Auto reset & restore
- [ ] Mail system
- [ ] Auto update
- [X] IP Preservation/Prevent translation to docker IP´s - FIXED: Added real_ip module configuration (Oct 18, 2025)
  - Added `real_ip_header X-Forwarded-For` to nginx.conf
  - Trust Docker networks: 172.16.0.0/12, 10.0.0.0/8, 192.168.0.0/16, localhost
  - Enabled `real_ip_recursive on` for multi-proxy chains
  - Bot detections and logs will now capture real client IPs instead of Docker gateway IPs
  