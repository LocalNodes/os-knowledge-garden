// SPDX-License-Identifier: MIT
pragma solidity 0.8.24;

import {PracticeMoney} from "../src/PracticeMoney.sol";

/// The few Foundry cheatcodes this script uses, declared here so it needs no
/// forge-std dependency.
interface Vm {
    function envUint(string calldata name) external view returns (uint256);
    function startBroadcast(uint256 privateKey) external;
    function stopBroadcast() external;
}

/// Deploys Practice Money through the standard CREATE2 deployer with the fixed
/// salt. The key is read here from the DEPLOYER_KEY environment variable, so it
/// never appears in any process's arguments. The transaction's gas limit is
/// DEPLOY_GAS_LIMIT, which script/deploy.sh works out and checks against its
/// ceiling: a broadcast call with an explicit gas amount is sent with exactly
/// that limit, and forge does not estimate it again. Run it only through
/// script/deploy.sh, which checks the chain first.
contract Deploy {
    Vm private constant vm = Vm(address(uint160(uint256(keccak256("hevm cheat code")))));

    address public constant CREATE2_DEPLOYER = 0x4e59b44847b379578588920cA78FbF26c0B4956C;
    bytes32 public constant SALT = keccak256("localnodes.practice-money.v1");

    function run() external returns (address token) {
        uint256 gasLimit = vm.envUint("DEPLOY_GAS_LIMIT");
        bytes memory data = abi.encodePacked(SALT, type(PracticeMoney).creationCode);
        vm.startBroadcast(vm.envUint("DEPLOYER_KEY"));
        (bool ok, bytes memory out) = CREATE2_DEPLOYER.call{gas: gasLimit}(data);
        vm.stopBroadcast();
        require(ok && out.length == 20, "the CREATE2 deployer did not deploy");
        token = address(bytes20(out));
    }
}
