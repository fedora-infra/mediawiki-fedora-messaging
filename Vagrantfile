# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure(2) do |config|
  config.hostmanager.enabled = true
  config.hostmanager.manage_host = true
  config.hostmanager.manage_guest = true

  config.vm.box_url = "https://download.fedoraproject.org/pub/fedora/linux/releases/36/Cloud/x86_64/images/Fedora-Cloud-Base-Vagrant-36-1.5.x86_64.vagrant-libvirt.box"
  config.vm.box = "f36-cloud-libvirt"
  config.vm.hostname = "wiki.tinystage.test"

  config.vm.synced_folder '.', '/vagrant', disabled: true
  config.vm.synced_folder ".", "/home/vagrant/dev", type: "sshfs"

  config.vm.provider :libvirt do |libvirt|
    libvirt.cpus = 1
    libvirt.memory = 2048
  end

  config.vm.provision "ansible" do |ansible|
    ansible.playbook = "devel/ansible/playbook.yml"
    ansible.config_file = "devel/ansible/ansible.cfg"
    ansible.verbose = true
  end

end
